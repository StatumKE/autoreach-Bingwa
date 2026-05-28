<?php

namespace App\Console\Commands;

use App\Jobs\RefreshAirtimeBalanceJob;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:fetch-airtime-balance')]
#[Description('Fetch the latest airtime balance for all registered devices.')]
class FetchAirtimeBalanceCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        Log::info('Starting automated airtime balance refresh command...');

        $users = User::query()
            ->with('bingwaDeviceRegistration')
            ->whereHas('bingwaDeviceRegistration', function ($query) {
                $query->where('status', '!=', 'stopped')
                    ->orWhereNull('status');
            })
            ->get();

        if ($users->isEmpty()) {
            Log::warning('Fetch airtime balance command skipped because no active device registration was found.');
            $this->warn('No active user found with a Bingwa device registration.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            Log::info('Dispatching RefreshAirtimeBalanceJob for user.', ['user_id' => $user->id]);
            RefreshAirtimeBalanceJob::dispatch($user);
        }

        $this->info(sprintf('Dispatched airtime balance refresh job for %d user(s).', $users->count()));

        return self::SUCCESS;
    }
}
