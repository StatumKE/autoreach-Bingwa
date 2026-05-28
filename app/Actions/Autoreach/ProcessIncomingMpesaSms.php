<?php

namespace App\Actions\Autoreach;

use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\DeviceSetting;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AppTimezone;
use App\Support\MpesaReceivedSms;
use App\Support\MpesaReceivedSmsParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessIncomingMpesaSms
{
    /**
     * @return array{status: string, mpesa_code?: string, transaction_id?: int, message?: string}
     */
    public function process(array $payload): array
    {
        $body = trim((string) ($payload['body'] ?? ''));
        $sender = trim((string) ($payload['sender'] ?? ''));

        if ($body === '' || $sender === '') {
            return $this->ignored('missing_sms_payload');
        }

        $user = $this->registeredUser();

        if (! $user instanceof User) {
            return $this->ignored('no_registered_user');
        }

        // deviceSetting is eager-loaded with the user — no extra query needed.
        $setting = $user->deviceSetting;

        if ($setting instanceof DeviceSetting && ! $setting->incoming_sms_enabled) {
            return $this->ignored('incoming_sms_disabled');
        }

        $simSlot = $this->normalizeSimSlot($payload['sim_slot'] ?? null);
        $configuredSimSlot = $setting?->incoming_sms_sim_slot ?? 'all';

        if (! $this->isAllowedSimSlot($configuredSimSlot, $simSlot)) {
            Log::info('Incoming M-Pesa SMS ignored because the SIM slot is not enabled.', [
                'user_id' => $user->getKey(),
                'configured_sim_slot' => $configuredSimSlot,
                'received_sim_slot' => $simSlot,
                'sender' => $this->redactSender($sender),
            ]);

            return $this->ignored('sim_slot_not_enabled');
        }

        $allowAllSenders = (bool) ($setting?->incoming_sms_allow_all_senders ?? false);

        if (! $this->parser->isTrustedSender($sender, $allowAllSenders)) {
            return $this->ignored('untrusted_sender');
        }

        $parsed = $this->parser->parse($body);

        if (! $parsed instanceof MpesaReceivedSms) {
            return $this->ignored('unparseable_sms');
        }

        if (Transaction::query()->where('mpesa_code', $parsed->code)->exists()) {
            Log::info('Duplicate incoming M-Pesa SMS ignored.', [
                'user_id' => $user->getKey(),
                'mpesa_code' => $parsed->code,
                'sender_phone' => $this->redactPhone($parsed->senderPhone),
            ]);

            return [
                'status' => 'duplicate',
                'mpesa_code' => $parsed->code,
            ];
        }

        // Resolve the plan once and reuse it below — eliminates the second DB
        // call that previously happened inside createQueuedTransaction / createFailedTransaction.
        $activePlan = $this->activePlan($user);
        $offer = $this->matchingOffer($user, $parsed);

        if (! $activePlan instanceof Plan) {
            $transaction = $this->createFailedTransaction(
                user: $user,
                parsed: $parsed,
                offer: $offer,
                payload: $payload,
                simSlot: $simSlot,
                statusDesc: __('No active subscription plan found.'),
            );

            return $this->failed($transaction, 'no_active_plan');
        }

        if (! $offer instanceof Offer) {
            $transaction = $this->createFailedTransaction(
                user: $user,
                parsed: $parsed,
                offer: null,
                payload: $payload,
                simSlot: $simSlot,
                statusDesc: __('No active offer found for the received amount.'),
            );

            return $this->failed($transaction, 'no_matching_offer');
        }

        $transaction = $this->createQueuedTransaction($user, $parsed, $offer, $payload, $simSlot);

        app()->call([(new ProcessBingwaQueuedTransactionsJob($user->getKey(), (string) Str::uuid())), 'handle']);

        $transaction->refresh();

        return [
            'status' => in_array($transaction->status, ['completed', 'failed'], true) ? 'processed' : 'queued',
            'mpesa_code' => $parsed->code,
            'transaction_id' => (int) $transaction->getKey(),
        ];
    }

    public function __construct(private MpesaReceivedSmsParser $parser) {}

    private function registeredUser(): ?User
    {
        return User::query()
            ->with(['bingwaDeviceRegistration', 'deviceSetting'])
            ->whereHas('bingwaDeviceRegistration', function ($query): void {
                $query->whereNotNull('device_token')
                    ->where('device_token', '!=', '')
                    ->where(function ($query): void {
                        $query->whereNull('status')
                            ->orWhere('status', '!=', 'stopped');
                    });
            })
            ->first();
    }

    private function activePlan(User $user): ?Plan
    {
        $activePlan = $user->plans()
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->oldest('id')
            ->first();

        if (! $activePlan instanceof Plan) {
            return null;
        }

        if (
            $activePlan->type === 'usage_pack'
            && $activePlan->ussd_requests_included !== null
            && $activePlan->ussd_counter >= $activePlan->ussd_requests_included
        ) {
            $activePlan->update(['is_active' => false]);

            return null;
        }

        return $activePlan;
    }

    private function matchingOffer(User $user, MpesaReceivedSms $parsed): ?Offer
    {
        $price = $parsed->amountAsOfferPrice();

        if ($price === null) {
            return null;
        }

        return $user->offers()
            ->where('is_active', true)
            ->where('price', $price)
            ->oldest('id')
            ->first();
    }

    private function createQueuedTransaction(
        User $user,
        MpesaReceivedSms $parsed,
        Offer $offer,
        array $payload,
        ?string $simSlot,
    ): Transaction {
        return DB::transaction(function () use ($offer, $parsed, $payload, $simSlot, $user): Transaction {
            return Transaction::query()->create([
                'user_id' => $user->getKey(),
                'offer_id' => $offer->getKey(),
                'transaction_id' => $this->transactionId($parsed),
                'mpesa_code' => $parsed->code,
                'sender_phone' => $parsed->senderPhone,
                'sender_name' => $parsed->senderName,
                'amount' => $parsed->amountAsFloat(),
                'offer_name' => $offer->name,
                'offer_type' => $offer->category,
                'matched_offer' => $this->matchedOffer($parsed, $payload, $simSlot, $offer),
                'occurred_at' => AppTimezone::now(),
                'next_attempt_at' => now(),
                'status' => 'queued',
                'status_desc' => __('Incoming M-Pesa SMS queued for processing.'),
                'processed_at' => null,
            ]);
        });
    }

    private function createFailedTransaction(
        User $user,
        MpesaReceivedSms $parsed,
        ?Offer $offer,
        array $payload,
        ?string $simSlot,
        string $statusDesc,
    ): Transaction {
        return DB::transaction(function () use ($offer, $parsed, $payload, $simSlot, $statusDesc, $user): Transaction {
            return Transaction::query()->create([
                'user_id' => $user->getKey(),
                'offer_id' => $offer?->getKey(),
                'transaction_id' => $this->transactionId($parsed),
                'mpesa_code' => $parsed->code,
                'sender_phone' => $parsed->senderPhone,
                'sender_name' => $parsed->senderName,
                'amount' => $parsed->amountAsFloat(),
                'offer_name' => $offer?->name ?? __('Unknown offer'),
                'offer_type' => $offer?->category ?? 'unknown',
                'matched_offer' => $this->matchedOffer($parsed, $payload, $simSlot, $offer),
                'occurred_at' => AppTimezone::now(),
                'next_attempt_at' => null,
                'status' => 'failed',
                'status_desc' => $statusDesc,
                'processed_at' => now(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function matchedOffer(MpesaReceivedSms $parsed, array $payload, ?string $simSlot, ?Offer $offer): array
    {
        return array_filter([
            'offer_local_id' => $offer instanceof Offer ? (string) $offer->getKey() : null,
            'offer_name' => $offer?->name,
            'offer_type' => $offer?->category,
            'offer_amount' => $offer?->price,
            'source' => 'mpesa_sms',
            'mpesa_code' => $parsed->code,
            'incoming_sms_sim_slot' => $simSlot,
            'incoming_sms_subscription_id' => $payload['subscription_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function transactionId(MpesaReceivedSms $parsed): string
    {
        return 'SMS-'.$parsed->code;
    }

    private function normalizeSimSlot(mixed $simSlot): ?string
    {
        $simSlot = trim((string) $simSlot);

        return in_array($simSlot, ['slot_1', 'slot_2'], true) ? $simSlot : null;
    }

    private function isAllowedSimSlot(string $configuredSimSlot, ?string $receivedSimSlot): bool
    {
        if ($configuredSimSlot === 'all') {
            return true;
        }

        return $receivedSimSlot !== null && $configuredSimSlot === $receivedSimSlot;
    }

    /**
     * @return array{status: string, message: string}
     */
    private function ignored(string $reason): array
    {
        return [
            'status' => 'ignored',
            'message' => $reason,
        ];
    }

    /**
     * @return array{status: string, mpesa_code: string, transaction_id: int, message: string}
     */
    private function failed(Transaction $transaction, string $reason): array
    {
        Log::info('Incoming M-Pesa SMS saved as failed local transaction.', [
            'transaction_id' => $transaction->getKey(),
            'mpesa_code' => $transaction->mpesa_code,
            'sender_phone' => $this->redactPhone($transaction->sender_phone),
            'reason' => $reason,
        ]);

        return [
            'status' => 'failed',
            'mpesa_code' => (string) $transaction->mpesa_code,
            'transaction_id' => (int) $transaction->getKey(),
            'message' => $reason,
        ];
    }

    private function redactPhone(string $phone): string
    {
        return strlen($phone) >= 4 ? str_repeat('*', max(strlen($phone) - 4, 0)).substr($phone, -4) : '****';
    }

    private function redactSender(string $sender): string
    {
        return Str::of($sender)->limit(2, '***')->toString();
    }
}
