<?php

namespace App\Actions\Autoreach;

use App\Jobs\SendAutoReplySmsJob;
use App\Models\Transaction;
use App\Support\AppTimezone;
use App\Support\BingwaTransactionFailureCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteBingwaTransaction
{
    /**
     * Persist one completed USSD attempt and fan out the follow-up work.
     */
    public function complete(
        int $transactionId,
        string $status,
        ?string $message = null,
        bool $finalizeOnce = false,
    ): bool {
        $transaction = Transaction::query()
            ->with(['user.deviceSetting', 'user.bingwaDeviceRegistration'])
            ->find($transactionId);

        if (! $transaction instanceof Transaction) {
            return false;
        }

        $statusDesc = match ($status) {
            'completed' => $message ?? __('USSD call completed successfully.'),
            'failed' => $message ?? __('USSD call failed.'),
        };

        DB::transaction(function () use ($transaction, $status, $statusDesc, $message, $finalizeOnce): void {
            $settings = $transaction->user?->deviceSetting;
            $lowerMessage = strtolower($message ?? '');

            $isModemBusy = str_contains($lowerMessage, 'another ussd session is already in progress')
                || str_contains($lowerMessage, 'modem is busy');

            if ($status === 'failed' && $isModemBusy) {
                $transaction->update([
                    'status' => 'queued',
                    'status_desc' => __('Another USSD session is already in progress.'),
                    ...$this->resetAutoReplyAttributes(),
                ]);

                Log::info('Bingwa transaction reverted to queued due to USSD modem lock contention.', [
                    'transaction_id' => $transaction->id,
                ]);

                return;
            }

            $isNonRetryable = str_contains($lowerMessage, 'already been recommended')
                || str_contains($lowerMessage, 'dashboard')
                || str_contains($lowerMessage, 'workspace');

            $isNetworkIssue = BingwaTransactionFailureCode::fromStatusAndMessage($status, $message) === 'USSD_TIMEOUT';
            if ($isNetworkIssue && ! ($settings?->retry_network_issues ?? true)) {
                $isNonRetryable = true;
            }

            if (! $finalizeOnce && $status === 'failed' && $settings?->intelligent_auto_retry && ! $isNonRetryable) {
                if ($transaction->retry_count < ($settings->max_attempts - 1)) {
                    $transaction->increment('retry_count');

                    $retryAt = now()->addMinutes($settings->retry_interval_minutes ?? 1);

                    $transaction->update([
                        'status' => 'queued',
                        'next_attempt_at' => $retryAt,
                        'status_desc' => __('Auto-retry attempt :count scheduled for :time.', [
                            'count' => $transaction->retry_count,
                            'time' => AppTimezone::format($retryAt, 'g:i A'),
                        ]),
                        ...$this->resetAutoReplyAttributes(),
                    ]);

                    Log::info('Bingwa transaction re-queued for intelligent retry.', [
                        'transaction_id' => $transaction->id,
                        'retry_count' => $transaction->retry_count,
                        'retry_at' => $retryAt,
                    ]);

                    return;
                }
            }

            if (! $finalizeOnce && $status === 'failed' && $settings?->auto_reschedule_rejected) {
                $isRejected = str_contains($lowerMessage, 'rejected')
                    || str_contains($lowerMessage, 'not allowed');

                if ($isRejected) {
                    $nextRun = now();
                    if ($settings->retry_tomorrow_at) {
                        $nextRun = now()->addDay()->setTimeFromTimeString($settings->retry_tomorrow_at);
                    }

                    $transaction->update([
                        'status' => 'queued',
                        'next_attempt_at' => $nextRun,
                        'status_desc' => __('Rescheduled for :time.', ['time' => AppTimezone::format($nextRun, 'g:i A')]),
                        ...$this->resetAutoReplyAttributes(),
                    ]);

                    Log::info('Bingwa transaction rescheduled after rejected carrier response.', [
                        'transaction_id' => $transaction->id,
                        'next_run' => $nextRun,
                    ]);

                    return;
                }
            }

            $transaction->update([
                'status' => $status,
                'status_desc' => $statusDesc,
                'processed_at' => now(),
            ]);

            $this->incrementPlanUsage($transaction, $status);
            $this->queueAutoReply($transaction, $status);
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

    private function queueAutoReply(Transaction $transaction, string $status): void
    {
        if (! in_array($status, ['completed', 'failed'], true)) {
            return;
        }

        $resolved = app(ResolveAutoReplyForTransaction::class)->resolve($transaction);

        $transaction->update([
            'auto_reply_id' => $resolved['auto_reply_id'],
            'auto_reply_trigger_condition' => $resolved['trigger_condition'],
            'auto_reply_message' => $resolved['reply_message'],
            'auto_reply_recipient_phone' => $resolved['recipient_phone'],
            'auto_reply_status' => $resolved['auto_reply_id'] !== null ? 'queued' : 'skipped',
            'auto_reply_attempts' => 0,
            'auto_reply_sent_at' => null,
            'auto_reply_failed_at' => null,
            'auto_reply_failure_reason' => $resolved['auto_reply_id'] !== null
                ? null
                : __('No active auto-reply matched the transaction outcome.'),
        ]);

        if ($resolved['auto_reply_id'] === null) {
            Log::info('Auto-reply skipped because no active rule matched the transaction outcome.', [
                'transaction_id' => $transaction->id,
                'status' => $status,
                'trigger_condition' => $resolved['trigger_condition'],
            ]);

            return;
        }

        SendAutoReplySmsJob::dispatch($transaction->id)->afterCommit();
    }

    /**
     * @return array<string, mixed>
     */
    private function resetAutoReplyAttributes(): array
    {
        return [
            'auto_reply_id' => null,
            'auto_reply_trigger_condition' => null,
            'auto_reply_message' => null,
            'auto_reply_recipient_phone' => null,
            'auto_reply_sim_slot' => null,
            'auto_reply_status' => null,
            'auto_reply_attempts' => 0,
            'auto_reply_sent_at' => null,
            'auto_reply_failed_at' => null,
            'auto_reply_failure_reason' => null,
        ];
    }
}
