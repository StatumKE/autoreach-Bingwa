<?php

namespace App\Actions\Autoreach;

use App\Models\AutoRenewal;
use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessDueAutoRenewals
{
    private const LOCK_KEY = 'bingwa:auto-renewals:process-due';

    /**
     * Convert due auto-renewal schedules into queued transaction rows.
     *
     * @return array{queued: int, rescheduled: int, failed: int, users: array<int, int>}
     */
    public function process(?int $userId = null): array
    {
        $user = User::query()->first();
        $userId = $user ? $user->id : null;

        $lock = Cache::lock(self::LOCK_KEY, 55);

        if (! $lock->get()) {
            Log::debug('Auto-renewal processing skipped because another worker is running.', [
                'user_id' => $userId,
            ]);

            return [
                'queued' => 0,
                'rescheduled' => 0,
                'failed' => 0,
                'users' => [],
            ];
        }

        try {
            return $this->processWithLock($userId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{queued: int, rescheduled: int, failed: int, users: array<int, int>}
     */
    private function processWithLock(?int $userId = null): array
    {
        $query = AutoRenewal::query()
            ->with(['offer', 'user'])
            ->where('status', 'scheduled')
            ->where('scheduled_for', '<=', now())
            ->oldest('scheduled_for')
            ->oldest('id');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $queued = 0;
        $rescheduled = 0;
        $failed = 0;
        $userIds = [];

        /** @var AutoRenewal $renewal */
        foreach ($query->get() as $renewal) {
            try {
                $result = DB::transaction(function () use ($renewal): array {
                    $claimed = AutoRenewal::query()
                        ->whereKey($renewal->id)
                        ->where('status', 'scheduled')
                        ->where('scheduled_for', '<=', now())
                        ->update([
                            'status' => 'processing',
                            'last_attempt_at' => now(),
                        ]);

                    if ($claimed === 0) {
                        return ['queued' => false, 'rescheduled' => false, 'skipped' => true];
                    }

                    $offer = $renewal->offer;

                    if (! $offer instanceof Offer || ! $offer->is_active) {
                        $renewal->update([
                            'status' => 'failed',
                            'processed_at' => now(),
                            'notes' => __('The scheduled offer is no longer active.'),
                        ]);

                        return ['queued' => false, 'rescheduled' => false, 'skipped' => false];
                    }

                    $renewal->setAttribute('status', 'processing');
                    $renewal->setAttribute('last_attempt_at', now());

                    $transaction = Transaction::query()->create([
                        'user_id' => $renewal->user_id,
                        'offer_id' => $offer->id,
                        'transaction_id' => 'AR-'.$renewal->id.'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
                        'mpesa_code' => null,
                        'sender_phone' => $renewal->customer_phone,
                        'sender_name' => null,
                        'amount' => $offer->price,
                        'offer_name' => $offer->name,
                        'offer_type' => $offer->category,
                        'matched_offer' => [
                            'offer_local_id' => (string) $offer->id,
                            'offer_name' => $offer->name,
                            'offer_type' => $offer->category,
                            'offer_amount' => $offer->price,
                            'source' => 'auto_renewal',
                            'auto_renewal_id' => $renewal->id,
                        ],
                        'occurred_at' => now(),
                        'next_attempt_at' => now(),
                        'status' => 'queued',
                        'status_desc' => __('Auto-renewal award queued for processing.'),
                        'processed_at' => null,
                    ]);

                    $shouldReschedule = $renewal->auto_renew && $renewal->renew_days > 1;

                    $renewal->update([
                        'status' => 'completed',
                        'processed_at' => now(),
                        'notes' => __('Queued transaction #:id.', ['id' => $transaction->id]),
                    ]);

                    if ($shouldReschedule) {
                        AutoRenewal::query()->create([
                            'user_id' => $renewal->user_id,
                            'offer_id' => $renewal->offer_id,
                            'customer_phone' => $renewal->customer_phone,
                            'scheduled_for' => $renewal->scheduled_for->copy()->addDay(),
                            'auto_renew' => true,
                            'renew_days' => $renewal->renew_days - 1,
                            'status' => 'scheduled',
                            'notes' => null,
                        ]);
                    }

                    return ['queued' => true, 'rescheduled' => $shouldReschedule, 'skipped' => false];
                });
            } catch (\Throwable $throwable) {
                $renewal->update([
                    'status' => 'failed',
                    'processed_at' => now(),
                    'notes' => $throwable->getMessage(),
                ]);

                report($throwable);

                Log::error('Auto-renewal processing failed.', [
                    'auto_renewal_id' => $renewal->id,
                    'user_id' => $renewal->user_id,
                    'message' => $throwable->getMessage(),
                ]);

                $failed++;

                continue;
            }

            if ($result['skipped']) {
                continue;
            }

            if ($result['queued']) {
                $queued++;
                $userIds[$renewal->user_id] = $renewal->user_id;
            } else {
                $failed++;
            }

            if ($result['rescheduled']) {
                $rescheduled++;
            }
        }

        return [
            'queued' => $queued,
            'rescheduled' => $rescheduled,
            'failed' => $failed,
            'users' => array_values($userIds),
        ];
    }
}
