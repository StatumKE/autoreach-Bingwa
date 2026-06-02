<?php

namespace App\Actions\Autoreach;

use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AppTimezone;
use DateTimeInterface;
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
        $activePlan ??= $user->activePlan();

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
        $type = $payload['offer_type'] ?? $fallbackOfferType ?? $payload['service'] ?? 'broadcast';
        $category = match ($type) {
            'data_bundles', 'data' => 'data',
            'sms' => 'sms',
            'airtime' => 'airtime',
            default => null,
        };

        $matchedOffer = $activeOffers
            ->filter(function ($offer) use ($category, $amount) {
                if ($category !== null && $offer->category !== $category) {
                    return false;
                }

                return $offer->price === (int) $amount;
            })
            ->first();

        if (! $matchedOffer) {
            $matchedOffer = $activeOffers->firstWhere('price', (int) $amount);
        }

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

        $occurredAt = $this->normalizeOccurredAt($payload['occurred_at'] ?? null);

        $transaction = DB::transaction(function () use ($amount, $fallbackOfferType, $mpesaCode, $occurredAt, $offerId, $payload, $status, $statusDesc, $transactionId, $user): Transaction {
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
                    'occurred_at' => $occurredAt,
                    'next_attempt_at' => $occurredAt,
                    'status' => $status,
                    'status_desc' => $statusDesc,
                    'processed_at' => $status === 'failed' ? now() : null,
                ],
            );
        });

        if ($transaction->status === 'failed') {
            app(QueueRemoteTransactionStatusUpdate::class)->queue(
                $transaction,
                'failed',
                $statusDesc,
                executedAt: now()->toIso8601String(),
            );
        }

        return [
            'transaction' => $transaction,
            'skipped' => false,
        ];
    }

    /**
     * Normalize a backend timestamp into the application timezone.
     *
     * Backend payloads may omit a timezone. When that happens we treat the
     * incoming value as UTC and convert it to Africa/Nairobi for storage.
     */
    private function normalizeOccurredAt(DateTimeInterface|Carbon|string|null $value): Carbon
    {
        if ($value === null || $value === '') {
            return AppTimezone::now();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->timezone(AppTimezone::name());
        }

        $hasExplicitTimezone = preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($value)) === 1;

        $timestamp = $hasExplicitTimezone
            ? Carbon::parse($value)
            : Carbon::parse($value, 'UTC');

        return $timestamp->timezone(AppTimezone::name());
    }
}
