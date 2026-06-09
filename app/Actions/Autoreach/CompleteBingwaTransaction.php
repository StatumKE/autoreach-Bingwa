<?php

namespace App\Actions\Autoreach;

use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Jobs\SendAutoReplySmsJob;
use App\Models\Transaction;
use App\Support\AppTimezone;
use App\Support\BingwaTransactionFailureCode;
use Illuminate\Support\Facades\Cache;
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
        ?string $callbackToken = null,
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

        Log::debug('Bingwa transaction completion started.', [
            'transaction_id' => $transaction->id,
            'transaction_key' => $transaction->transaction_id,
            'status' => $status,
            'callback_token' => $callbackToken,
        ]);

        if (is_string($callbackToken) && $callbackToken !== '' && DB::table('ussd_callback_deliveries')->where('callback_token', $callbackToken)->exists()) {
            Log::debug('Bingwa transaction completion skipped because the callback token was already recorded.', [
                'transaction_id' => $transaction->id,
                'transaction_key' => $transaction->transaction_id,
                'callback_token' => $callbackToken,
            ]);

            return true;
        }

        if (in_array($transaction->status, ['completed', 'failed'], true) && filled($transaction->processed_at)) {
            if (is_string($callbackToken) && $callbackToken !== '') {
                $this->recordCallbackDelivery($callbackToken, $transaction, $status, $message);
            }

            Log::debug('Bingwa transaction completion skipped because the transaction was already finalized.', [
                'transaction_id' => $transaction->id,
                'transaction_key' => $transaction->transaction_id,
                'status' => $transaction->status,
                'callback_token' => $callbackToken,
            ]);

            return true;
        }

        $statusDesc = match ($status) {
            'completed' => $message ?? __('USSD call completed successfully.'),
            'failed' => $message ?? __('USSD call failed.'),
        };

        $isGenericFailure = $status === 'failed' && str_contains(strtolower((string) $message), 'network returned a generic failure');

        if ($isGenericFailure && $transaction->retry_count < 3) {
            DB::transaction(function () use ($transaction, $statusDesc) {
                $transaction->update([
                    'status' => 'queued',
                    'status_desc' => $statusDesc.' (Retrying '.($transaction->retry_count + 1).'/3)',
                    'next_attempt_at' => now()->addSeconds(30),
                    'processed_at' => null,
                    'retry_count' => $transaction->retry_count + 1,
                ]);
            });

            // Dispatch the next queued job to keep the queue processing autonomously
            if (! ProcessBingwaQueuedTransactionsJob::$isProcessing) {
                app(DispatchBingwaQueuedTransactionsJob::class)->dispatch((int) $transaction->user_id);
            }

            Log::warning('Bingwa transaction generic network failure caught. Queued for automatic retry.', [
                'transaction_id' => $transaction->id,
                'retry_count' => $transaction->retry_count,
            ]);

            return true;
        }

        $isDailyLimitHit = $status === 'failed' && str_contains((string) $message, 'Recommendation failed. The customer');
        $nextAttemptAt = null;

        if ($isDailyLimitHit) {
            $transaction->loadMissing('user.deviceSetting');
            $settings = $transaction->user?->deviceSetting;

            if ($settings && $settings->retry_tomorrow_at) {
                $nextAttemptAt = now()->addDay()->setTimeFromTimeString($settings->retry_tomorrow_at);
            }
        }

        // Capture notification parameters before the transaction so they are available
        // inside the afterCommit callback without re-querying the (now-closed) connection.
        $remoteStatus = $status === 'completed' ? 'successful' : 'failed';
        $failureCode = BingwaTransactionFailureCode::fromStatusAndMessage($status, $message);

        DB::transaction(function () use ($transaction, $status, $statusDesc, $nextAttemptAt): void {
            $transaction->status = $status;
            $transaction->status_desc = $statusDesc;

            // Resolve auto-reply before the final UPDATE so both the core status
            // fields and the auto-reply fields are written in a single round-trip.
            $resolved = app(ResolveAutoReplyForTransaction::class)->resolve($transaction);

            $autoReplyQueued = $resolved['auto_reply_id'] !== null;

            $transaction->update([
                'status' => $status,
                'status_desc' => $statusDesc,
                'next_attempt_at' => $nextAttemptAt,
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
        });

        // Notify the Autoreach backend AFTER the transaction commits successfully.
        // Running this inside DB::transaction held row-level locks for up to 30 s and
        // caused split-brain state when an HTTP timeout triggered a DB rollback.
        DB::afterCommit(function () use ($transaction, $remoteStatus, $message, $failureCode): void {
            app(QueueRemoteTransactionStatusUpdate::class)->queue(
                $transaction,
                $remoteStatus,
                $message,
                executionTimeMs: $transaction->updated_at ? (int) abs(now()->diffInMilliseconds($transaction->updated_at)) : null,
                executedAt: now()->toIso8601String(),
                failureCode: $failureCode,
            );
        });

        if (is_string($callbackToken) && $callbackToken !== '') {
            $this->recordCallbackDelivery($callbackToken, $transaction, $status, $message);
        }

        $cacheKey = 'dashboard:metrics:'.$transaction->user_id.':'.AppTimezone::now()->toDateString();
        Cache::forget($cacheKey);

        // Dispatch the next queued job to keep the queue processing autonomously if we are not already processing
        if (! ProcessBingwaQueuedTransactionsJob::$isProcessing) {
            app(DispatchBingwaQueuedTransactionsJob::class)->dispatch((int) $transaction->user_id);
        }

        Log::info('Bingwa transaction completion finished.', [
            'transaction_id' => $transaction->id,
            'transaction_key' => $transaction->transaction_id,
            'status' => $status,
            'callback_token' => $callbackToken,
            'message' => $statusDesc,
        ]);

        return true;
    }

    private function incrementPlanUsage(Transaction $transaction, string $status): void
    {
        $user = $transaction->user;

        if (! $user || $status !== 'completed') {
            return;
        }

        $activePlan = $user->activePlan();
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

    private function recordCallbackDelivery(
        string $callbackToken,
        Transaction $transaction,
        string $status,
        ?string $message,
    ): void {
        DB::table('ussd_callback_deliveries')->updateOrInsert(
            ['callback_token' => $callbackToken],
            [
                'transaction_key' => (string) $transaction->transaction_id,
                'transaction_id' => $transaction->id,
                'status' => $status,
                'message' => $message,
                'delivered_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        Log::debug('Bingwa callback delivery recorded.', [
            'transaction_id' => $transaction->id,
            'transaction_key' => $transaction->transaction_id,
            'callback_token' => $callbackToken,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
