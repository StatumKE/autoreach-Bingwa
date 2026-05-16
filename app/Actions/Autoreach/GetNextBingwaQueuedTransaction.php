<?php

namespace App\Actions\Autoreach;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class GetNextBingwaQueuedTransaction
{
    private const STUCK_THRESHOLD_MINUTES = 45;

    /**
     * Find the next queued Bingwa transaction and prepare its USSD payload.
     *
     * Returns null when there is nothing to process.
     *
     * @return array<string, mixed>|null
     */
    public function next(?int $userId = null): ?array
    {
        $this->recoverStuckTransactions();

        $transactionQuery = Transaction::query()
            ->with(['offer:id,ussd_code,ussd_mode', 'user.deviceSetting', 'user.bingwaDeviceRegistration', 'user.plans'])
            ->where('status', 'queued');

        if ($userId !== null) {
            $transactionQuery->where('user_id', $userId);
        }

        $transaction = $transactionQuery
            ->oldest('occurred_at')
            ->first();

        if ($transaction === null) {
            return null;
        }

        // Self-healing: If offer_id is missing, try to re-match it now
        if ($transaction->offer_id === null && $transaction->amount > 0) {
            $matchedOffer = $transaction->user?->offers()
                ->where('is_active', true)
                ->where('price', (int) $transaction->amount)
                ->first();

            if ($matchedOffer) {
                $transaction->update(['offer_id' => $matchedOffer->id]);
                $transaction->setRelation('offer', $matchedOffer);
                Log::info("Self-healed transaction #{$transaction->id}: Linked to offer #{$matchedOffer->id}");
            }
        }

        if ($transaction->offer === null) {
            Log::warning("Transaction #{$transaction->id} skipped: No matching offer found for amount {$transaction->amount}");

            return null;
        }

        $activePlan = $transaction->user?->plans()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $activePlan) {
            $transaction->update([
                'status' => 'failed',
                'status_desc' => __('Subscription expired or deactivated while waiting in queue.'),
            ]);

            Log::warning("🚫 Dispatch blocked for job #{$transaction->id}: No active plan found.");

            return ['skip' => true, 'id' => $transaction->id];
        }

        if ($activePlan->type === 'usage_pack' && $activePlan->ussd_requests_included !== null) {
            if ($activePlan->ussd_counter >= $activePlan->ussd_requests_included) {
                $activePlan->update(['is_active' => false]);
                $transaction->update([
                    'status' => 'failed',
                    'status_desc' => __('Subscription usage limit reached.'),
                ]);

                Log::warning("🚫 Dispatch blocked for job #{$transaction->id}: Plan usage limit reached.");

                return ['skip' => true, 'id' => $transaction->id];
            }
        }

        $resolvedCode = str_replace('PN', $transaction->sender_phone, $transaction->offer->ussd_code);
        $settings = $transaction->user?->deviceSetting;
        $simSlot = ($settings?->primary_transaction_sim === 'slot_2') ? 1 : 0;
        $timeout = $settings?->ussd_timeout_seconds ?? 30;

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

        Log::info("📤 Dispatching job v2 #{$transaction->id} | Code: {$resolvedCode} | Mode: {$transaction->offer->ussd_mode}");
        Log::info("📡 USSD payload prepared for local job #{$transaction->id}.");

        return $payload;
    }

    private function recoverStuckTransactions(): int
    {
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
