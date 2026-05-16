<?php

namespace App\Actions\Autoreach;

use App\Models\AutoReply;
use App\Models\Transaction;
use App\Support\BingwaUssdResponse;
use App\Support\KenyanPhoneNumber;

class ResolveAutoReplyForTransaction
{
    /**
     * Resolve the active auto-reply for a finalized transaction.
     *
     * @return array{
     *     trigger_condition: string,
     *     auto_reply_id: int|null,
     *     auto_reply_name: string|null,
     *     reply_message: string|null,
     *     recipient_phone: string,
     * }
     */
    public function resolve(Transaction $transaction): array
    {
        $triggerCondition = $this->resolveTriggerCondition($transaction);
        $reply = $this->activeReplyForTrigger($transaction, $triggerCondition);
        $recipientPhone = KenyanPhoneNumber::normalizeToLocal((string) $transaction->sender_phone);

        return [
            'trigger_condition' => $triggerCondition,
            'auto_reply_id' => $reply?->id,
            'auto_reply_name' => $reply?->name,
            'reply_message' => $reply !== null ? $this->renderMessage($reply->reply_message, $transaction) : null,
            'recipient_phone' => $recipientPhone,
        ];
    }

    private function resolveTriggerCondition(Transaction $transaction): string
    {
        $status = strtolower(trim((string) $transaction->status));
        $message = strtolower(trim((string) preg_replace('/\s+/', ' ', $transaction->status_desc ?? '')));

        if ($status === 'completed' || BingwaUssdResponse::messageIndicatesSuccess($transaction->status_desc)) {
            return 'successful_transaction';
        }

        if (
            str_contains($message, 'already been recommended')
            || str_contains($message, 'already recommended')
            || str_contains($message, 'already purchased')
        ) {
            return 'already_recommended';
        }

        if (
            str_contains($message, 'no matching offer')
            || str_contains($message, 'price mismatch')
            || str_contains($message, 'offer unavailable')
            || str_contains($message, 'no active offer')
            || str_contains($message, 'does not match')
        ) {
            return 'offer_unavailable';
        }

        if (
            str_contains($message, 'paused')
            || str_contains($message, 'dashboard')
            || str_contains($message, 'workspace')
            || str_contains($message, 'unavailable')
        ) {
            return 'app_paused';
        }

        if (
            str_contains($message, 'blacklisted')
            || str_contains($message, 'cannot receive')
            || str_contains($message, 'blocked')
        ) {
            return 'blacklisted_customer';
        }

        return 'failed_transaction';
    }

    private function activeReplyForTrigger(Transaction $transaction, string $triggerCondition): ?AutoReply
    {
        return AutoReply::query()
            ->where('user_id', $transaction->user_id)
            ->where('trigger_condition', $triggerCondition)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    private function renderMessage(string $template, Transaction $transaction): string
    {
        return strtr($template, $this->placeholderValues($transaction));
    }

    /**
     * @return array<string, string>
     */
    private function placeholderValues(Transaction $transaction): array
    {
        $name = trim((string) $transaction->sender_name);
        $nameParts = $name !== '' ? preg_split('/\s+/', $name) ?: [] : [];

        $firstName = $nameParts[0] ?? 'Customer';
        $surname = count($nameParts) > 1 ? (string) last($nameParts) : '';
        $amount = number_format((float) $transaction->amount, 2, '.', '');
        $amount = rtrim(rtrim($amount, '0'), '.');

        return [
            '<firstName>' => $firstName,
            '<surname>' => $surname,
            '<mpesaCode>' => (string) ($transaction->mpesa_code ?? ''),
            '<amount>' => $amount,
            '<offerName>' => (string) ($transaction->offer_name ?? ''),
            '<offerPrice>' => $amount,
        ];
    }
}
