<?php

namespace App\Console\Commands;

use App\Models\DeviceSetting;
use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:next-ussd-job {--output= : Path to write the JSON payload to}')]
#[Description('Get the next queued transaction and output its USSD details as JSON for the Android scheduler.')]
class NextUssdJobCommand extends Command
{
    /**
     * Transactions stuck in "processing" longer than this are assumed crashed and recovered.
     */
    private const STUCK_THRESHOLD_MINUTES = 45;

    /**
     * Execute the console command.
     *
     * Outputs a single-line JSON payload consumed by the Android scheduler worker.
     * Outputs nothing (exit 0) if there are no pending jobs.
     */
    public function handle(): int
    {
        try {
            Log::debug('NextUssdJobCommand started.');

            // Recover any transactions stuck in "processing" due to a previous crash.
            // The USSD timeout is 30 seconds, so anything older than 45 minutes is definitively stuck.
            $recoveredCount = Transaction::query()
                ->where('status', 'processing')
                ->where('updated_at', '<=', now()->subMinutes(self::STUCK_THRESHOLD_MINUTES))
                ->update([
                    'status' => 'queued',
                    'status_desc' => __('Recovered: previous USSD attempt timed out.'),
                ]);

            if ($recoveredCount > 0) {
                Log::warning("♻️ Recovered {$recoveredCount} stuck transactions.");
            }

            $transaction = Transaction::query()
                ->with(['offer:id,ussd_code,ussd_mode', 'user.deviceSetting', 'user.bingwaDeviceRegistration', 'user.plans'])
                ->where('status', 'queued')
                ->where(function ($query): void {
                    $query->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', now());
                })
                ->whereNotNull('offer_id')
                ->oldest('occurred_at')
                ->first();

            if ($transaction === null || $transaction->offer === null) {
                Log::debug('NextUssdJobCommand found no queued transaction.');

                return self::SUCCESS;
            }

            if (! DeviceSetting::isTransactionProcessingEnabledForUser((int) $transaction->user_id)) {
                Log::info('⏸️ NextUssdJobCommand skipped because transaction processing is paused.', [
                    'transaction_id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                ]);

                return self::SUCCESS;
            }

            // Final safety check: Does the user still have a valid plan?
            // This handles cases where the plan expired while the job was waiting in the local queue.
            $activePlan = $transaction->user?->activePlan();

            if (! $activePlan) {
                $transaction->update([
                    'status' => 'failed',
                    'status_desc' => __('Subscription expired or deactivated while waiting in queue.'),
                ]);
                Log::warning("🚫 Dispatch blocked for job #{$transaction->id}: No active plan found.");

                return self::SUCCESS;
            }

            // Replace the PN placeholder with the actual recipient phone number
            $resolvedCode = str_replace('PN', $transaction->sender_phone, $transaction->offer->ussd_code);

            Log::info("📤 Dispatching job v2 #{$transaction->id} | Code: {$resolvedCode} | Mode: {$transaction->offer->ussd_mode}");

            $settings = $transaction->user?->deviceSetting;
            $simSlot = ($settings?->primary_transaction_sim === 'slot_2') ? 1 : 0;
            $timeout = $settings?->ussd_timeout_seconds ?? 60;

            $payload = [
                'id' => $transaction->id,
                'backend_transaction_id' => $transaction->transaction_id,
                'code' => $resolvedCode,
                'mode' => $transaction->offer->ussd_mode,
                'sim_slot' => $simSlot,
                'timeout' => (int) $timeout,
                'backend_url' => rtrim((string) config('services.autoreach.backend_url'), '/'),
                'device_token' => $transaction->user?->bingwaDeviceRegistration?->device_token,
            ];

            $json = json_encode($payload);
            Log::info("📡 USSD payload prepared for local job #{$transaction->id}.");

            $outputPath = $this->option('output');
            Log::info('📝 DEBUG_OUTPUT_PATH: '.($outputPath ?: 'NONE'));

            if ($outputPath) {
                $result = file_put_contents($outputPath, $json);
                Log::info('📝 DEBUG_WRITE_RESULT: '.($result !== false ? 'SUCCESS' : 'FAILED'));
            } else {
                $this->output->write($json);
            }

            Log::debug('NextUssdJobCommand completed.', [
                'transaction_id' => $transaction->id,
                'output_path' => $outputPath ?: null,
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('❌ NextUssdJobCommand failed: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
