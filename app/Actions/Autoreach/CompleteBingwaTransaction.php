<?php

namespace App\Actions\Autoreach;

use App\Jobs\SendAutoReplySmsJob;
use App\Models\Transaction;
use App\Support\BingwaTransactionFailureCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteBingwaTransaction
{
    /**
     * Persist one completed USSD attempt and fan out the follow-up work.
     *
     * Accepts either a Transaction model (preferred — avoids an extra DB fetch)
     * or a plain integer ID for backwards-compatibility.
     *
     * Failed transactions are always finalised immediately as `failed`.
     * Retries are initiated manually by the user — no automatic re-queue.
     */
    public function complete(
        int|Transaction $transactionId,
        string $status,
        ?string $message = null,
    ): bool {
        // Accept a pre-loaded model so the caller does not pay for a redundant
        // DB round-trip when it already has the model in memory.
        if ($transactionId instanceof Transaction) {
            $transaction = $transactionId->loadMissing(['user.bingwaDeviceRegistration']);
        } else {
            $transaction = Transaction::query()
                ->with(['user.bingwaDeviceRegistration'])
                ->find($transactionId);
        }

        if (! $transaction instanceof Transaction) {
            return false;
        }

        $statusDesc = match ($status) {
            'completed' => $message ?? __('USSD call completed successfully.'),
            'failed' => $message ?? __('USSD call failed.'),
        };

        DB::transaction(function () use ($transaction, $status, $statusDesc, $message): void {
            // Resolve auto-reply before the final UPDATE so both the core status
            // fields and the auto-reply fields are written in a single round-trip.
            $resolved = app(ResolveAutoReplyForTransaction::class)->resolve($transaction);

            $autoReplyQueued = $resolved['auto_reply_id'] !== null;

            $transaction->update([
                'status' => $status,
                'status_desc' => $statusDesc,
                'processed_at' => now(),
                'auto_reply_id' => $resolved['auto_reply_id'],
                'auto_reply_trigger_condition' => $resolved['trigger_condition'],
                'auto_reply_message' => $resolved['reply_message'],
                'auto_reply_recipient_phone' => $resolved['recipient_phone'],
                'auto_reply_status' => $autoReplyQueued ? 'queued' : null,
                'auto_reply_attempts' => 0,
                'auto_reply_sent_at' => null,
                'auto_reply_failed_at' => null,
                'auto_reply_failure_reason' => null,
            ]);

            $this->incrementPlanUsage($transaction, $status);

            if (! $autoReplyQueued) {
                Log::info('Auto-reply skipped because no active rule matched the transaction outcome.', [
                    'transaction_id' => $transaction->id,
                    'status' => $status,
                    'trigger_condition' => $resolved['trigger_condition'],
                ]);
            } else {
                SendAutoReplySmsJob::dispatch($transaction->id)->afterCommit();
            }

            app(QueueRemoteTransactionStatusUpdate::class)->queue(
                $transaction,
                $status === 'completed' ? 'successful' : 'failed',
                $message,
                executionTimeMs: $transaction->updated_at ? (int) abs(now()->diffInMilliseconds($transaction->updated_at)) : null,
                executedAt: now()->toIso8601String(),
                failureCode: BingwaTransactionFailureCode::fromStatusAndMessage($status, $message),
            );
        });

        return true;
    }

    private function incrementPlanUsage(Transaction $transaction, string $status): void
    {
        $user = $transaction->user;

        if (! $user || $status !== 'completed') {
            return;
        }

        $activePlan = $user->plans()->where('is_active', true)->first();
        if (! $activePlan) {
            return;
        }

        $activePlan->increment('ussd_counter');
        $activePlan->refresh();

        if ($activePlan->type === 'usage_pack'
            && $activePlan->ussd_requests_included !== null
            && $activePlan->ussd_counter >= $activePlan->ussd_requests_included
        ) {
            $activePlan->update(['is_active' => false]);
        }
    }
}
