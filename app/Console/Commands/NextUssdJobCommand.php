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
     * Execute the console command.
     *
     * Outputs a JSON payload consumed by ArtisanSchedulerService.kt to drive the USSD call.
     * Outputs nothing if there are no pending jobs.
     */
    public function handle(): int
    {
        $transaction = Transaction::query()
            ->with('offer:id,ussd_code,ussd_mode')
            ->whereIn('status', ['queued'])
            ->whereNotNull('offer_id')
            ->oldest('occurred_at')
            ->first();

        if ($transaction === null || $transaction->offer === null) {
            return self::SUCCESS;
        }

        $ussdCode = $transaction->offer->ussd_code;

        // Replace the PN placeholder with the actual recipient phone number
        $resolvedCode = str_replace('PN', $transaction->sender_phone, $ussdCode);

        // Mark immediately as processing so the next loop iteration skips it
        $transaction->update(['status' => 'processing', 'status_desc' => __('USSD call in progress.')]);

        $this->line(json_encode([
            'id' => $transaction->id,
            'code' => $resolvedCode,
            'mode' => $transaction->offer->ussd_mode,
        ]));

        return self::SUCCESS;
    }
}
