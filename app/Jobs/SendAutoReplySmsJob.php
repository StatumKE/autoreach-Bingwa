<?php

namespace App\Jobs;

use App\Actions\Autoreach\SendAutoReplySms;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendAutoReplySmsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public readonly int $transactionId,
    ) {}

    public function uniqueId(): string
    {
        return 'auto-reply-sms:'.$this->transactionId;
    }

    public function handle(SendAutoReplySms $sender): void
    {
        Log::debug('Auto-reply SMS job started.', [
            'component' => 'auto_reply_sms',
            'transaction_id' => $this->transactionId,
        ]);

        $transaction = Transaction::query()
            ->with(['user.deviceSetting'])
            ->find($this->transactionId);

        if ($transaction === null) {
            Log::debug('Auto-reply SMS job skipped because the transaction was not found.', [
                'component' => 'auto_reply_sms',
                'transaction_id' => $this->transactionId,
            ]);

            return;
        }

        $claimed = $this->claimTransaction($transaction);

        if ($claimed === null) {
            return;
        }

        $response = $sender->send(
            (string) $claimed->auto_reply_recipient_phone,
            (string) $claimed->auto_reply_message,
            $claimed->auto_reply_sim_slot === 'slot_2' ? 1 : 0,
        );

        if ($response['success']) {
            $claimed->update([
                'auto_reply_status' => 'sent',
                'auto_reply_sent_at' => now(),
                'auto_reply_failed_at' => null,
                'auto_reply_failure_reason' => null,
            ]);

            Log::info('Auto-reply SMS sent.', [
                'component' => 'auto_reply_sms',
                'transaction_id' => $claimed->id,
                'auto_reply_id' => $claimed->auto_reply_id,
                'recipient_phone' => $claimed->auto_reply_recipient_phone,
            ]);

            return;
        }

        $claimed->update([
            'auto_reply_status' => 'failed',
            'auto_reply_failed_at' => now(),
            'auto_reply_failure_reason' => $response['message'],
        ]);

        Log::warning('Auto-reply SMS failed.', [
            'component' => 'auto_reply_sms',
            'transaction_id' => $claimed->id,
            'auto_reply_id' => $claimed->auto_reply_id,
            'recipient_phone' => $claimed->auto_reply_recipient_phone,
            'retryable' => (bool) $response['retryable'],
            'message' => $response['message'],
        ]);

        Log::debug('Auto-reply SMS job finished.', [
            'component' => 'auto_reply_sms',
            'transaction_id' => $claimed->id,
            'retryable' => (bool) $response['retryable'],
        ]);

        if ($response['retryable']) {
            throw new RuntimeException($response['message']);
        }
    }

    /**
     * Handle a job failure after all retries are exhausted or the worker crashes.
     */
    public function failed(Throwable $exception): void
    {
        $transaction = Transaction::query()->find($this->transactionId);

        if ($transaction !== null && $transaction->auto_reply_status === 'sending') {
            $transaction->update([
                'auto_reply_status' => 'failed',
                'auto_reply_failed_at' => now(),
                'auto_reply_failure_reason' => $exception->getMessage(),
            ]);
        }

        Log::error('Auto-reply SMS job failed.', [
            'component' => 'auto_reply_sms',
            'transaction_id' => $this->transactionId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }

    private function claimTransaction(Transaction $transaction): ?Transaction
    {
        $currentAttempt = max(1, $this->attempts());

        return DB::transaction(function () use ($transaction, $currentAttempt): ?Transaction {
            $lockedTransaction = Transaction::query()
                ->with(['user.deviceSetting'])
                ->lockForUpdate()
                ->find($transaction->id);

            if ($lockedTransaction === null) {
                return null;
            }

            if (! in_array($lockedTransaction->status, ['completed', 'failed'], true)) {
                return null;
            }

            if (! in_array($lockedTransaction->auto_reply_status, [null, 'queued', 'failed', 'sending'], true)) {
                return null;
            }

            if ($lockedTransaction->auto_reply_status === 'sending' && $lockedTransaction->auto_reply_attempts >= $currentAttempt) {
                return null;
            }

            if (blank($lockedTransaction->auto_reply_message) || blank($lockedTransaction->auto_reply_recipient_phone)) {
                return null;
            }

            $simSlotIndex = $this->resolveSimSlotIndex($lockedTransaction);

            $lockedTransaction->update([
                'auto_reply_status' => 'sending',
                'auto_reply_attempts' => $currentAttempt,
                'auto_reply_sim_slot' => $simSlotIndex === 1 ? 'slot_2' : 'slot_1',
                'auto_reply_failure_reason' => null,
            ]);

            return $lockedTransaction->fresh(['user.deviceSetting']);
        });
    }

    private function resolveSimSlotIndex(Transaction $transaction): int
    {
        return $transaction->user?->deviceSetting?->sms_auto_reply_sim === 'slot_2' ? 1 : 0;
    }
}
