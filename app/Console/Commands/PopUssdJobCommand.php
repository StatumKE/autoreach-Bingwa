<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:pop-ussd-job {--output= : Path to write the JSON payload to}')]
#[Description('Atomically find, claim, and return the next queued USSD job.')]
class PopUssdJobCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            // 1. Recovery Logic (Business Logic Preservation)
            $recoveredCount = Transaction::query()
                ->where('status', 'processing')
                ->where('updated_at', '<=', now()->subMinutes(2))
                ->update([
                    'status' => 'queued',
                    'status_desc' => __('Recovered: previous USSD attempt timed out.'),
                ]);

            if ($recoveredCount > 0) {
                Log::warning("♻️ Recovered {$recoveredCount} stuck transactions during pop.");
            }

            // 2. Atomic Find & Claim
            $payload = DB::transaction(function () {
                $transaction = Transaction::query()
                    ->with(['offer:id,ussd_code,ussd_mode', 'user.deviceSetting', 'user.bingwaDeviceRegistration', 'user.plans'])
                    ->where('status', 'queued')
                    ->whereNotNull('offer_id')
                    ->oldest('occurred_at')
                    ->lockForUpdate() // SQLite doesn't strictly need this but good for semantic clarity
                    ->first();

                if (! $transaction) {
                    return null;
                }

                // 3. Subscription Validation (Business Logic Preservation)
                $activePlan = $transaction->user?->plans()
                    ->where('is_active', true)
                    ->where(function ($query) {
                        $query->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->first();

                if (! $activePlan) {
                    $transaction->update([
                        'status' => 'failed',
                        'status_desc' => __('Subscription expired or deactivated while waiting in queue.'),
                    ]);

                    return ['skip' => true, 'id' => $transaction->id];
                }

                if ($activePlan->type === 'usage_pack' && $activePlan->ussd_requests_included !== null) {
                    if ($activePlan->ussd_counter >= $activePlan->ussd_requests_included) {
                        $activePlan->update(['is_active' => false]);
                        $transaction->update([
                            'status' => 'failed',
                            'status_desc' => __('Subscription usage limit reached.'),
                        ]);

                        return ['skip' => true, 'id' => $transaction->id];
                    }
                }

                // 4. Claim the job
                $transaction->update(['status' => 'processing']);

                $resolvedCode = str_replace('PN', $transaction->sender_phone, $transaction->offer->ussd_code);
                $settings = $transaction->user?->deviceSetting;
                $simSlot = ($settings?->primary_transaction_sim === 'slot_2') ? 1 : 0;
                $timeout = $settings->ussd_timeout_seconds ?? 30;

                return [
                    'id' => $transaction->id,
                    'backend_transaction_id' => $transaction->transaction_id,
                    'code' => $resolvedCode,
                    'mode' => $transaction->offer->ussd_mode,
                    'sim_slot' => $simSlot,
                    'timeout' => (int) $timeout,
                    'backend_url' => rtrim((string) config('services.autoreach.backend_url'), '/'),
                    'device_token' => $transaction->user?->bingwaDeviceRegistration?->device_token,
                    'claimed' => true,
                ];
            });

            if (! $payload) {
                return self::SUCCESS;
            }

            // If we skipped a failed/expired transaction, try again immediately for the next one
            if (isset($payload['skip'])) {
                Log::debug("⏩ Skipped invalid transaction #{$payload['id']}, looking for next...");

                return $this->handle();
            }

            $json = json_encode($payload);
            $outputPath = $this->option('output');

            if ($outputPath) {
                file_put_contents($outputPath, $json);
            } else {
                $this->output->write($json);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('❌ PopUssdJobCommand failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
