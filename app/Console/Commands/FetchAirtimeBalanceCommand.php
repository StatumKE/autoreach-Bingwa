<?php

namespace App\Console\Commands;

use App\Jobs\RefreshAirtimeBalanceJob;
use App\Services\BingwaDeviceContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:fetch-airtime-balance')]
#[Description('Fetch the latest airtime balance for the registered device.')]
class FetchAirtimeBalanceCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BingwaDeviceContext $deviceContext): int
    {
        Log::info('Starting automated airtime balance refresh command...', [
            'component' => 'airtime_balance',
            'command' => 'bingwa:fetch-airtime-balance',
        ]);

        $registration = $deviceContext->registration();

        if ($registration === null || $registration->user === null || $registration->status === 'stopped') {
            Log::warning('Fetch airtime balance command skipped because no active device registration was found.');
            $this->warn('No active device registration found.');

            return self::SUCCESS;
        }

        Log::info('Dispatching RefreshAirtimeBalanceJob.', [
            'component' => 'airtime_balance',
            'user_id' => $registration->user->getKey(),
        ]);
        RefreshAirtimeBalanceJob::dispatch($registration->user->getKey());

        $this->info('Dispatched airtime balance refresh job.');

        return self::SUCCESS;
    }
}
