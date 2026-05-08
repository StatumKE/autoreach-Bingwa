<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\FetchNextBingwaJobs;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:sync-transactions {--user-id= : Restrict sync to a specific user ID}')]
#[Description('Pull the next queued Bingwa jobs from the backend and store them locally.')]
class SyncBingwaTransactions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FetchNextBingwaJobs $fetchNextBingwaJobs): int
    {
        Log::info('🔄 Starting Bingwa transactions sync...');

        $userQuery = User::query()
            ->with('bingwaDeviceRegistration')
            ->whereHas('bingwaDeviceRegistration');

        if ($this->option('user-id')) {
            $userQuery->whereKey((int) $this->option('user-id'));
        }

        $user = $userQuery->first();

        if ($user) {
            Log::debug("👤 Syncing for user ID: {$user->id}");
        }

        if (! $user) {
            $this->warn('No user found with a Bingwa device registration. Skipping sync.');

            return self::SUCCESS;
        }

        $result = $fetchNextBingwaJobs->sync($user);

        $synced = $result['synced'];
        $failed = $result['failed'];
        $skipped = $result['skipped'];

        $data = [
            'synced' => $synced,
            'skipped' => $skipped,
            'failed' => $failed,
            'message' => "✅ Sync complete: {$synced} synced, {$skipped} skipped, {$failed} failed.",
        ];

        Log::info($data['message']);
        $this->output->write(json_encode($data));

        return self::SUCCESS;
    }
}
