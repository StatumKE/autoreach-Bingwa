<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\DispatchBingwaQueuedTransactionsJob;
use App\Actions\Autoreach\FetchNextBingwaJobs;
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

            Log::debug('Bingwa transactions sync skipped because no user was found.', [
                'user_id' => $this->option('user-id') ? (int) $this->option('user-id') : null,
            ]);

            return self::SUCCESS;
        }

        Log::debug('Bingwa transactions sync fetching backend jobs.', [
            'user_id' => $user->id,
        ]);

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

        Log::debug('Bingwa transactions sync backend fetch finished.', [
            'user_id' => $user->id,
            'synced' => $synced,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        if ($synced > 0 || Transaction::query()->where('status', 'queued')->exists()) {
            Log::info('🚀 Dispatching USSD processor for queued transactions...');
            app(DispatchBingwaQueuedTransactionsJob::class)->dispatch((int) $user->id);

            Log::debug('Bingwa transactions sync dispatched queued processor.', [
                'user_id' => $user->id,
            ]);
        }

        return self::SUCCESS;
    }
}
