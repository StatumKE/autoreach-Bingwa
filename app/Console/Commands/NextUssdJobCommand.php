<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:next-ussd-job')]
#[Description('Get the next queued transaction and output its USSD details as JSON for the Android scheduler.')]
class NextUssdJobCommand extends Command
{
    /**
     * Transactions stuck in "processing" longer than this are assumed crashed and recovered.
     */
    private const STUCK_THRESHOLD_MINUTES = 2;

    /**
     * Execute the console command.
     *
     * Outputs a single-line JSON payload consumed by ArtisanSchedulerService.kt.
     * Outputs nothing (exit 0) if there are no pending jobs.
     */
    public function handle(): int
    {
        // Recover any transactions stuck in "processing" due to a previous crash.
        // The USSD timeout is 30 seconds, so anything older than 2 minutes is definitively stuck.
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
            ->with(['offer:id,ussd_code,ussd_mode', 'user.deviceSetting'])
            ->where('status', 'queued')
            ->whereNotNull('offer_id')
            ->oldest('occurred_at')
            ->first();

        if ($transaction === null || $transaction->offer === null) {
            Log::debug('📭 No queued transactions found.');

            return self::SUCCESS;
        }

        // Replace the PN placeholder with the actual recipient phone number
        $resolvedCode = str_replace('PN', $transaction->sender_phone, $transaction->offer->ussd_code);

        Log::info("📤 Dispatching job #{$transaction->id} | Code: {$resolvedCode} | Mode: {$transaction->offer->ussd_mode}");

        $settings = $transaction->user?->deviceSetting;
        $simSlot = ($settings?->primary_transaction_sim === 'slot_2') ? 1 : 0;
        $timeout = $settings->ussd_timeout_seconds ?? 30;

        $this->line(json_encode([
            'id' => $transaction->id,
            'code' => $resolvedCode,
            'mode' => $transaction->offer->ussd_mode,
            'sim_slot' => $simSlot,
            'timeout' => $timeout,
        ]));

        return self::SUCCESS;
    }
}
