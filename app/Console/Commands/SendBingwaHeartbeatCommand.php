<?php

namespace App\Console\Commands;

use App\Jobs\SendHeartbeatJob;
use App\Models\BingwaDeviceRegistration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:heartbeat')]
#[Description('Send a heartbeat to the Bingwa backend for all registered devices.')]
class SendBingwaHeartbeatCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {

        $registration = BingwaDeviceRegistration::query()
            ->where(function ($query) {
                $query->where('status', '!=', 'stopped')
                    ->orWhereNull('status');
            })
            ->first();

        if (! $registration) {
            Log::warning('Bingwa heartbeat command skipped because no active device registration was found.');
            $this->warn('No active device registration found. Skipping heartbeat.');

            return self::SUCCESS;
        }

        SendHeartbeatJob::dispatch();

        Log::info('Bingwa heartbeat job queued.');

        $this->info('Heartbeat job queued.');

        return self::SUCCESS;
    }
}
