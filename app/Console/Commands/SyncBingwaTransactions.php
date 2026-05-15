<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\FetchNextBingwaJobs;
use App\Console\Commands\Concerns\SkipsOnFreshBoot;
use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('bingwa:sync-transactions {--user-id= : Restrict sync to a specific user ID}')]
#[Description('Pull the next queued Bingwa jobs from the backend and store them locally.')]
class SyncBingwaTransactions extends Command
{
    use SkipsOnFreshBoot;

    /**
     * Execute the console command.
     */
    public function handle(FetchNextBingwaJobs $fetchNextBingwaJobs): int
    {
        if ($this->isInFreshBootGracePeriod()) {
            return self::SUCCESS;
        }

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

        if ($synced > 0 || Transaction::query()->where('status', 'queued')->exists()) {
            Log::info('🚀 Dispatching USSD processor for queued transactions...');
            ProcessBingwaQueuedTransactionsJob::dispatch($user->id);
        }

        return self::SUCCESS;
    }
}
