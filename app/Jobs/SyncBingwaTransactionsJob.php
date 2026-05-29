<?php

namespace App\Jobs;

use App\Actions\Autoreach\FetchNextBingwaJobs;
use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncBingwaTransactionsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public array $pushData = [],
    ) {}

    public function uniqueId(): string
    {
        return 'bingwa-sync-transactions';
    }

    /**
     * Execute the job.
     *
     * Previously this job invoked the `bingwa:sync-transactions` Artisan command
     * which bootstrapped a full Kernel dispatch cycle for every sync — significant
     * unnecessary overhead. We now call the underlying action classes directly,
     * which is equivalent in behaviour but far cheaper at runtime.
     */
    public function handle(
        FetchNextBingwaJobs $fetchNextBingwaJobs,
    ): void {
        $user = User::query()->first();

        if (! $user instanceof User) {
            Log::warning('Bingwa transaction sync job skipped because no user was found.');

            return;
        }

        Log::debug('Bingwa transaction sync job started.', [
            'user_id' => $user->id,
            'push_data' => $this->pushData,
        ]);

        if ($user->bingwaDeviceRegistration === null) {
            Log::debug('Bingwa transaction sync job skipped because user has no device registration.', [
                'user_id' => $user->id,
            ]);

            return;
        }

        $onlyService = $this->pushData['service'] ?? null;
        $result = $fetchNextBingwaJobs->sync($user, 10, $onlyService);

        Log::debug('Bingwa transaction sync job finished fetching.', [
            'user_id' => $user->id,
            'synced' => $result['synced'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ]);

        // Dispatch the USSD processor synchronously when there are newly synced jobs
        // or any existing queued transactions still waiting to be processed.
        // Doing this synchronously skips the latency of queueing a second job.
        if ($result['synced'] > 0 || Transaction::query()->where('status', 'queued')->exists()) {
            if (! DeviceSetting::isTransactionProcessingEnabledForUser($user->id)) {
                Log::debug('Bingwa transaction processing skipped because processing is paused.', [
                    'user_id' => $user->id,
                ]);

                return;
            }

            Log::debug('Bingwa transaction sync job dispatching queued processor synchronously.', [
                'user_id' => $user->id,
            ]);

            ProcessBingwaQueuedTransactionsJob::dispatchSync();
        }
    }

    public function failed(Throwable $exception): void
    {
        $user = User::query()->first();
        Log::error('Bingwa transaction sync job marked as failed.', [
            'user_id' => $user?->id,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
