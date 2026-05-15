<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\SendBingwaHeartbeat;
use App\Console\Commands\Concerns\SkipsOnFreshBoot;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:heartbeat')]
#[Description('Send a heartbeat to the Bingwa backend for all registered devices.')]
class SendBingwaHeartbeatCommand extends Command
{
    use SkipsOnFreshBoot;

    /**
     * Execute the console command.
     */
    public function handle(SendBingwaHeartbeat $sendBingwaHeartbeat): int
    {
        if ($this->isInFreshBootGracePeriod()) {
            return self::SUCCESS;
        }

        $user = User::query()
            ->with('bingwaDeviceRegistration')
            ->whereHas('bingwaDeviceRegistration', function ($query) {
                $query->where('status', '!=', 'stopped')
                    ->orWhereNull('status');
            })
            ->first();

        if ($user) {
            $sent = $sendBingwaHeartbeat->send($user);
            Log::info('Bingwa heartbeat command finished.', [
                'user_id' => $user->getKey(),
                'sent' => $sent,
            ]);

            if (! $sent) {
                $this->error('Heartbeat was not accepted by the backend.');

                return self::FAILURE;
            }

            $this->info('Heartbeat sent for the registered user.');
        } else {
            Log::warning('Bingwa heartbeat command skipped because no active device registration was found.');
            $this->warn('No active user found with a Bingwa device registration. Skipping heartbeat.');
        }

        return self::SUCCESS;
    }
}
