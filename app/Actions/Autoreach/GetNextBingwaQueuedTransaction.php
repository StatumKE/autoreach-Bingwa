<?php

namespace App\Actions\Autoreach;

use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GetNextBingwaQueuedTransaction
{
    private const STUCK_THRESHOLD_MINUTES = 45;

    /**
     * Find the next queued Bingwa transaction and prepare its USSD payload.
     *
     * Returns null when there is nothing to process.
     *
     * @param  bool|null  $isProcessingEnabled  Pre-resolved flag so the caller
     *                                          avoids a redundant cache/DB read.
     *                                          When null the value is resolved here
     *                                          via Cache::memo() (memory-first).
     * @return array<string, mixed>|null
     */
    public function next(?int $userId = null, ?bool $isProcessingEnabled = null): ?array
    {
        $this->recoverStuckTransactions();

        $user = User::query()->first();
        if ($user === null) {
            return null;
        }
        $userId = $user->id;

        // Use the caller-supplied flag when available; fall back to a
        // Cache::memo() lookup so repeated calls within the same job
        // execution hit memory instead of Redis/DB every time.
        $enabled = $isProcessingEnabled ?? Cache::memo()->remember(
            "user_{$userId}_transaction_processing_enabled",
            300,
            fn () => DeviceSetting::isTransactionProcessingEnabledForUser($userId),
        );

        if (! $enabled) {
            Log::info('Bingwa USSD processor lookup skipped because processing is paused.', [
                'user_id' => $userId,
            ]);

            return null;
        }

        $transactionQuery = Transaction::query()
            ->with([
                'offer:id,ussd_code,ussd_mode',
                'user:id',
                'user.deviceSetting',
                'user.bingwaDeviceRegistration',
            ])
            ->where(function ($query): void {
                $query->where(function ($q) {
                    $q->where('status', 'queued')
                        ->where(function ($sq) {
                            $sq->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', now());
                        });
                })->orWhere(function ($q) {
                    $q->where('status', 'failed')
                        ->whereNotNull('next_attempt_at')
                        ->where('next_attempt_at', '<=', now());
                });
            });

        if ($userId !== null) {
            $transactionQuery->where('user_id', $userId);
        }

        // ORDER BY id ASC — id is a monotonically increasing surrogate key, so it
        // reliably processes transactions in arrival order. It is always unique (no
        // ties), already the clustered key pointer in the index, and paired with the
        // covering index (user_id, status, next_attempt_at, id) the sort is satisfied
        // entirely from the index with zero filesort overhead.
        $transaction = $transactionQuery
            ->oldest('occurred_at')
            ->first();

        if ($transaction === null) {
            return null;
        }

        // Self-healing: If offer_id is missing, try to re-match it now.
        if ($transaction->offer_id === null && $transaction->amount > 0) {
            $category = match ($transaction->offer_type) {
                'data_bundles', 'data' => 'data',
                'sms' => 'sms',
                'airtime' => 'airtime',
                default => null,
            };

            $matchedOffer = $transaction->user?->offers()
                ->where('is_active', true)
                ->when($category, fn ($query) => $query->where('category', $category))
                ->where('price', (int) $transaction->amount)
                ->first();

            if (! $matchedOffer) {
                $matchedOffer = $transaction->user?->offers()
                    ->where('is_active', true)
                    ->where('price', (int) $transaction->amount)
                    ->first();
            }

            if ($matchedOffer) {
                $transaction->update(['offer_id' => $matchedOffer->id]);
                $transaction->setRelation('offer', $matchedOffer);
                Log::info("Self-healed transaction #{$transaction->id}: Linked to offer #{$matchedOffer->id}");
            }
        }

        if ($transaction->offer === null) {
            $transaction->update([
                'status' => 'failed',
                'status_desc' => __('No matching offer found for the transaction amount.'),
                'processed_at' => now(),
            ]);

            app(QueueRemoteTransactionStatusUpdate::class)->queue(
                $transaction,
                'failed',
                $transaction->status_desc,
                executedAt: now()->toIso8601String(),
            );

            Log::warning("Transaction #{$transaction->id} failed: No matching offer found for amount {$transaction->amount}");

            return ['skip' => true, 'id' => $transaction->id];
        }

        $activePlan = $transaction->user?->activePlan();

        if (! $activePlan) {
            $transaction->update([
                'status' => 'failed',
                'status_desc' => __('Subscription expired or deactivated while waiting in queue.'),
                'processed_at' => now(),
            ]);

            app(QueueRemoteTransactionStatusUpdate::class)->queue(
                $transaction,
                'failed',
                $transaction->status_desc,
                executedAt: now()->toIso8601String(),
            );

            Log::warning("🚫 Dispatch blocked for job #{$transaction->id}: No active plan found.");

            return ['skip' => true, 'id' => $transaction->id];
        }

        $resolvedCode = str_replace('PN', $transaction->sender_phone, $transaction->offer->ussd_code);
        $settings = $transaction->user?->deviceSetting;
        $simSlot = ($settings?->primary_transaction_sim === 'slot_2') ? 1 : 0;
        $timeout = $settings?->ussd_timeout_seconds ?? 60;

        $payload = [
            'id' => $transaction->id,
            'backend_transaction_id' => $transaction->transaction_id,
            'code' => $resolvedCode,
            'mode' => $transaction->offer->ussd_mode,
            'sim_slot' => $simSlot,
            'timeout' => (int) $timeout,
            'backend_url' => rtrim((string) config('services.autoreach.backend_url'), '/'),
            'device_token' => $transaction->user?->bingwaDeviceRegistration?->device_token,
        ];

        // Single structured log entry — replaces the two back-to-back Log::info calls.
        Log::info("📤 USSD payload ready for job #{$transaction->id}", [
            'code' => $resolvedCode,
            'mode' => $transaction->offer->ussd_mode,
            'sim_slot' => $simSlot,
        ]);

        return $payload;
    }

    private function recoverStuckTransactions(): int
    {
        // Cache::memo() ensures the rate-limiting gate is memory-fast when called
        // multiple times within the same job execution loop.
        $lastRunTimestamp = Cache::memo()->get('last_stuck_recovery_run_at');

        if (is_int($lastRunTimestamp) && (now()->timestamp - $lastRunTimestamp) < 300) {
            return 0;
        }

        Cache::put('last_stuck_recovery_run_at', now()->timestamp, 300);

        $recoveredCount = Transaction::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes(self::STUCK_THRESHOLD_MINUTES))
            ->update([
                'status' => 'queued',
                'status_desc' => __('Recovered: previous USSD attempt timed out.'),
            ]);

        if ($recoveredCount > 0) {
            Log::warning("♻️ Recovered {$recoveredCount} stuck transactions.");
        }

        return $recoveredCount;
    }
}
