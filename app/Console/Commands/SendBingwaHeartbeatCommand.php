<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\SendBingwaHeartbeat;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bingwa:heartbeat')]
#[Description('Send a heartbeat to the Bingwa backend for all registered devices.')]
class SendBingwaHeartbeatCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SendBingwaHeartbeat $sendBingwaHeartbeat): int
    {
        $user = User::query()
            ->with('bingwaDeviceRegistration')
            ->whereHas('bingwaDeviceRegistration', function ($query) {
                $query->where('status', '!=', 'stopped')
                    ->orWhereNull('status');
            })
            ->first();

        if ($user) {
            $sendBingwaHeartbeat->send($user);
            $this->info('Heartbeat sent for the registered user.');
        } else {
            $this->warn('No active user found with a Bingwa device registration. Skipping heartbeat.');
        }

        return self::SUCCESS;
    }
}
