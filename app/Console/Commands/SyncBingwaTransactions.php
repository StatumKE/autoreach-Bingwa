<?php

namespace App\Console\Commands;

use App\Jobs\SyncBingwaTransactionsJob;
use App\Services\BingwaDeviceContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:sync-transactions')]
#[Description('Pull the next queued Bingwa jobs from the backend and store them locally.')]
class SyncBingwaTransactions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BingwaDeviceContext $deviceContext): int
    {
        Log::info('🔄 Starting Bingwa transactions sync...', [
            'command' => 'bingwa:sync-transactions',
        ]);

        $registration = $deviceContext->registration();

        if ($registration === null || $registration->user === null) {
            $this->warn('No Bingwa device registration found. Skipping sync.');

            Log::debug('Bingwa transactions sync skipped because no registration was found.');

            return self::SUCCESS;
        }

        Log::debug('Bingwa transactions sync dispatching background job.');

        SyncBingwaTransactionsJob::dispatch($registration->user->getKey());

        if (function_exists('nativephp_call')) {
            try {
                nativephp_call('WakeQueueWorker', '{}');
            } catch (\Throwable $e) {
                // Ignore if bridge is unavailable
            }
        }

        $this->info("Dispatched sync job for {$registration->user->phone}");

        $data = [
            'queued' => true,
            'message' => '✅ Sync job queued successfully.',
        ];

        Log::info($data['message'], [
            'queued' => true,
            'user_id' => $registration->user->getKey(),
        ]);
        $this->output->write(json_encode($data));

        return self::SUCCESS;
    }
}
