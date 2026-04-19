<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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
        Transaction::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes(self::STUCK_THRESHOLD_MINUTES))
            ->update([
                'status' => 'queued',
                'status_desc' => __('Recovered: previous USSD attempt timed out.'),
            ]);

        $transaction = Transaction::query()
            ->with('offer:id,ussd_code,ussd_mode')
            ->where('status', 'queued')
            ->whereNotNull('offer_id')
            ->oldest('occurred_at')
            ->first();

        if ($transaction === null || $transaction->offer === null) {
            return self::SUCCESS;
        }

        // Replace the PN placeholder with the actual recipient phone number
        $resolvedCode = str_replace('PN', $transaction->sender_phone, $transaction->offer->ussd_code);

        // Mark as processing immediately to prevent double-dispatch on the next cycle
        $transaction->update([
            'status' => 'processing',
            'status_desc' => __('USSD call in progress.'),
        ]);

        $this->line(json_encode([
            'id' => $transaction->id,
            'code' => $resolvedCode,
            'mode' => $transaction->offer->ussd_mode,
        ]));

        return self::SUCCESS;
    }
}
