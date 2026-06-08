<?php

namespace App\Console\Commands;

use App\Jobs\SendHeartbeatJob;
use App\Services\BingwaDeviceContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:heartbeat')]
#[Description('Send a heartbeat to the Bingwa backend for the registered device.')]
class SendBingwaHeartbeatCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BingwaDeviceContext $deviceContext): int
    {
        $registration = $deviceContext->registration();

        if ($registration === null || $registration->user === null) {
            Log::warning('Bingwa heartbeat command skipped because no active device registration was found.');
            $this->warn('No active device registration found. Skipping heartbeat.');

            return self::SUCCESS;
        }

        Log::debug('Bingwa heartbeat command dispatching job.', [
            'user_id' => $registration->user->getKey(),
            'registration_id' => $registration->getKey(),
        ]);

        SendHeartbeatJob::dispatch($registration->user->getKey());

        Log::info('Bingwa heartbeat job queued.', [
            'user_id' => $registration->user->getKey(),
            'registration_id' => $registration->getKey(),
        ]);

        $this->info('Heartbeat job queued.');

        return self::SUCCESS;
    }
}
