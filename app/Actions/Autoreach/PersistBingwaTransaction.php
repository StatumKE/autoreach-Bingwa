<?php

namespace App\Actions\Autoreach;

use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PersistBingwaTransaction
{
    /**
     * Persist a queued Bingwa transaction payload locally.
     *
     * @param  array<string, mixed>  $payload
     * @return array{transaction: Transaction|null, skipped: bool}
     */
    public function persist(
        User $user,
        array $payload,
        ?Collection $activeOffers = null,
        ?Plan $activePlan = null,
        ?string $fallbackOfferType = null,
    ): array {
        $registration = $user->bingwaDeviceRegistration;

        if ($registration === null || blank($registration->device_token)) {
            return [
                'transaction' => null,
                'skipped' => true,
            ];
        }

        $transactionId = trim((string) ($payload['transaction_id'] ?? ''));

        if ($transactionId === '') {
            return [
                'transaction' => null,
                'skipped' => true,
            ];
        }

        $activeOffers ??= $user->offers()->where('is_active', true)->get();
        $activePlan ??= $user->plans()->where('is_active', true)->first();

        if ($activePlan !== null) {
            $shouldDeactivate = false;

            if ($activePlan->type === 'time_unlimited' && $activePlan->expires_at && now()->isAfter($activePlan->expires_at)) {
                $shouldDeactivate = true;
            } elseif ($activePlan->type === 'usage_pack' && $activePlan->ussd_requests_included !== null && $activePlan->ussd_counter >= $activePlan->ussd_requests_included) {
                $shouldDeactivate = true;
            }

            if ($shouldDeactivate) {
                $activePlan->update(['is_active' => false]);
                $activePlan = null;
            }
        }

        $existingTransaction = Transaction::query()
            ->where('transaction_id', $transactionId)
            ->first(['id', 'status']);

        if (
            $existingTransaction !== null
            && in_array($existingTransaction->status, ['processing', 'completed', 'failed'], true)
        ) {
            return [
                'transaction' => null,
                'skipped' => true,
            ];
        }

        $mpesaCode = trim((string) ($payload['mpesa_code'] ?? ''));

        if ($mpesaCode !== '') {
            $existingMpesaTransaction = Transaction::query()
                ->where('mpesa_code', $mpesaCode)
                ->where('transaction_id', '!=', $transactionId)
                ->first(['id', 'transaction_id']);

            if ($existingMpesaTransaction !== null) {
                return [
                    'transaction' => null,
                    'skipped' => true,
                ];
            }
        }

        $amount = (float) ($payload['amount'] ?? 0);
        $matchedOffer = $activeOffers->firstWhere('price', (int) $amount);
        $status = 'queued';
        $statusDesc = __('Pulled from backend job queue.');
        $offerId = $matchedOffer?->getKey();

        if (! $activePlan) {
            $status = 'failed';
            $statusDesc = __('No active subscription plan found.');
        } elseif (! $matchedOffer) {
            $status = 'failed';
            $statusDesc = __("Price mismatch: No active offer found for amount {$amount}.");
        }

        $transaction = DB::transaction(function () use ($amount, $fallbackOfferType, $mpesaCode, $offerId, $payload, $status, $statusDesc, $transactionId, $user): Transaction {
            return Transaction::query()->updateOrCreate(
                ['transaction_id' => $transactionId],
                [
                    'user_id' => $user->id,
                    'offer_id' => $offerId,
                    'mpesa_code' => $mpesaCode !== '' ? $mpesaCode : null,
                    'sender_phone' => $payload['sender_phone'] ?? '',
                    'sender_name' => $payload['sender_name'] ?? null,
                    'amount' => $amount,
                    'offer_name' => $payload['offer_name'] ?? __('Unknown offer'),
                    'offer_type' => $payload['offer_type'] ?? $fallbackOfferType ?? $payload['service'] ?? 'broadcast',
                    'matched_offer' => $payload['matched_offer'] ?? null,
                    'balance' => null,
                    'occurred_at' => Carbon::parse($payload['occurred_at'] ?? now())
                        ->setTimezone((string) config('app.timezone')),
                    'status' => $status,
                    'status_desc' => $statusDesc,
                ],
            );
        });

        return [
            'transaction' => $transaction,
            'skipped' => false,
        ];
    }
}
