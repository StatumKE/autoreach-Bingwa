<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\FetchNextBingwaJobs;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bingwa:sync-transactions')]
#[Description('Pull the next queued Bingwa jobs from the backend and store them locally.')]
class SyncBingwaTransactions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FetchNextBingwaJobs $fetchNextBingwaJobs): int
    {
        $synced = 0;
        $failed = 0;
        $skipped = 0;

        User::query()
            ->with('bingwaDeviceRegistration')
            ->whereHas('bingwaDeviceRegistration')
            ->chunkById(50, function ($users) use (&$synced, &$failed, &$skipped, $fetchNextBingwaJobs): void {
                foreach ($users as $user) {
                    $result = $fetchNextBingwaJobs->sync($user);

                    $synced += $result['synced'];
                    $failed += $result['failed'];
                    $skipped += $result['skipped'];
                }
            });

        $this->info("Synced {$synced} transaction(s). Skipped {$skipped} account(s). Failed {$failed} endpoint(s).");

        return self::SUCCESS;
    }
}
