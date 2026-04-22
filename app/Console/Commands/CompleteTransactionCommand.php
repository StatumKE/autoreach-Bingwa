<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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

        // Intelligent Auto-Retry Logic
        if (! $finalizeOnce && $status === 'failed' && $settings?->intelligent_auto_retry) {
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
        if ($user) {
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
