<?php

namespace App\Console\Commands;

use App\Jobs\UpdateRemoteTransactionStatusJob;
use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Signature('bingwa:complete-transaction {id? : The local transaction ID} {status? : completed or failed} {--transaction-id= : The local transaction ID} {--result= : completed or failed} {--message= : Optional status description} {--message-base64= : Optional base64-encoded status description} {--finalize-once : Finalize immediately without auto-retry or reschedule}')]
#[Description('Mark a transaction as completed or failed after a USSD execution attempt.')]
class CompleteTransactionCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $id = (int) ($this->option('transaction-id') ?? $this->argument('id'));
        $status = (string) ($this->option('result') ?? $this->argument('status'));

        if ($id <= 0) {
            $this->error('A transaction ID must be provided.');

            return self::FAILURE;
        }

        if (! in_array($status, ['completed', 'failed'], true)) {
            $this->error("Invalid status '{$status}'. Must be 'completed' or 'failed'.");

            return self::FAILURE;
        }

        $transaction = Transaction::query()->with('user.deviceSetting')->find($id);

        if (! $transaction) {
            $this->warn("Transaction #{$id} not found.");

            return self::SUCCESS;
        }

        try {
            $message = $this->resolveMessageOption();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $statusDesc = match ($status) {
            'completed' => $message ?? __('USSD call completed successfully.'),
            'failed' => $message ?? __('USSD call failed.'),
        };

        $settings = $transaction->user?->deviceSetting;
        $finalizeOnce = (bool) $this->option('finalize-once');

        return DB::transaction(function () use ($transaction, $id, $status, $statusDesc, $message, $settings, $finalizeOnce) {
            // Intelligent Auto-Retry Logic
            $isNonRetryable = str_contains(strtolower($message ?? ''), 'already been recommended')
                           || str_contains(strtolower($message ?? ''), 'dashboard')
                           || str_contains(strtolower($message ?? ''), 'workspace');

            if (! $finalizeOnce && $status === 'failed' && $settings?->intelligent_auto_retry && ! $isNonRetryable) {
                if ($transaction->retry_count < ($settings->max_attempts - 1)) {
                    $transaction->increment('retry_count');

                    $retryAt = now()->addMinutes($settings->retry_interval_minutes ?? 1);

                    $transaction->update([
                        'status' => 'queued',
                        'occurred_at' => $retryAt,
                        'status_desc' => __('Auto-retry attempt :count scheduled for :time.', [
                            'count' => $transaction->retry_count,
                            'time' => $retryAt->format('g:i A'),
                        ]),
                    ]);

                    $this->info("Transaction #{$id} re-queued for retry.");

                    return self::SUCCESS;
                }
            }

            // Auto Reschedule Rejected Logic
            if (! $finalizeOnce && $status === 'failed' && $settings?->auto_reschedule_rejected) {
                $isRejected = str_contains(strtolower($message ?? ''), 'rejected')
                           || str_contains(strtolower($message ?? ''), 'not allowed');

                if ($isRejected) {
                    $nextRun = now();
                    if ($settings->retry_tomorrow_at) {
                        $nextRun = now()->addDay()->setTimeFromTimeString($settings->retry_tomorrow_at);
                    }

                    $transaction->update([
                        'status' => 'queued',
                        'occurred_at' => $nextRun,
                        'status_desc' => __('Rescheduled for :time.', ['time' => $nextRun->format('g:i A')]),
                    ]);

                    $this->info("Transaction #{$id} rescheduled for tomorrow.");

                    return self::SUCCESS;
                }
            }

            $transaction->update([
                'status' => $status,
                'status_desc' => $statusDesc,
                'processed_at' => now(),
            ]);

            $user = $transaction->user;
            if ($user && $status === 'completed') {
                $activePlan = $user->plans()->where('is_active', true)->first();
                if ($activePlan) {
                    $activePlan->increment('ussd_counter');

                    // Refresh to get the new counter value
                    $activePlan->refresh();

                    if ($activePlan->type === 'usage_pack' && $activePlan->ussd_requests_included !== null) {
                        if ($activePlan->ussd_counter >= $activePlan->ussd_requests_included) {
                            $activePlan->update(['is_active' => false]);
                            $this->info("Plan '{$activePlan->name}' has been exhausted and deactivated.");
                        }
                    }
                }
            }

            // Dispatch update to remote backend
            $remoteTransactionId = $transaction->transaction_id;
            $deviceToken = $transaction->user?->bingwaDeviceRegistration?->device_token;

            if ($remoteTransactionId && $deviceToken) {
                $executionTimeMs = $transaction->updated_at ? (int) abs(now()->diffInMilliseconds($transaction->updated_at)) : null;
                $failureCode = null;

                if ($status === 'failed') {
                    $lowerMessage = strtolower($message ?? '');
                    if (str_contains($lowerMessage, 'already been recommended') || str_contains($lowerMessage, 'invalid') || str_contains($lowerMessage, 'not allowed') || str_contains($lowerMessage, 'rejected')) {
                        $failureCode = 'INVALID_RECIPIENT';
                    } elseif (str_contains($lowerMessage, 'insufficient') || str_contains($lowerMessage, 'low balance') || str_contains($lowerMessage, 'not enough')) {
                        $failureCode = 'LOW_BALANCE';
                    } elseif (str_contains($lowerMessage, 'timeout') || str_contains($lowerMessage, 'failed to execute') || str_contains($lowerMessage, 'failed to reach')) {
                        $failureCode = 'USSD_TIMEOUT';
                    } elseif (str_contains($lowerMessage, 'session ended') || str_contains($lowerMessage, 'cancelled')) {
                        $failureCode = 'SESSION_ENDED';
                    } else {
                        $failureCode = 'SYSTEM_ERROR';
                    }
                }

                UpdateRemoteTransactionStatusJob::dispatch(
                    $remoteTransactionId,
                    $deviceToken,
                    $status === 'completed' ? 'successful' : 'failed',
                    $message,
                    null, // airtime_used is optional, omitting for now
                    $executionTimeMs,
                    now()->toIso8601String(),
                    $failureCode
                );
            }

            return self::SUCCESS;
        });

        return self::SUCCESS;
    }

    private function resolveMessageOption(): ?string
    {
        $encodedMessage = $this->option('message-base64');

        if (is_string($encodedMessage)) {
            $decodedMessage = base64_decode($encodedMessage, true);

            if ($decodedMessage === false) {
                throw new InvalidArgumentException('The --message-base64 option must contain valid base64.');
            }

            return $decodedMessage;
        }

        $message = $this->option('message');

        return is_string($message) ? $message : null;
    }
}
