<?php

namespace App\Jobs;

use App\Actions\Autoreach\DispatchBingwaQueuedTransactionsJob;
use App\Actions\Autoreach\FetchNextBingwaJobs;
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
        public int $userId,
        public array $pushData = [],
    ) {}

    public function uniqueId(): string
    {
        return 'bingwa-sync-transactions:'.$this->userId;
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
        DispatchBingwaQueuedTransactionsJob $dispatchBingwaQueuedTransactionsJob,
    ): void {
        Log::debug('Bingwa transaction sync job started.', [
            'user_id' => $this->userId,
            'push_data' => $this->pushData,
        ]);

        $user = User::query()
            ->with('bingwaDeviceRegistration')
            ->find($this->userId);

        if (! $user instanceof User) {
            Log::warning('Bingwa transaction sync job skipped because user was not found.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        if ($user->bingwaDeviceRegistration === null) {
            Log::debug('Bingwa transaction sync job skipped because user has no device registration.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        $result = $fetchNextBingwaJobs->sync($user);

        Log::debug('Bingwa transaction sync job finished fetching.', [
            'user_id' => $this->userId,
            'synced' => $result['synced'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ]);

        // Dispatch the USSD processor when there are newly synced jobs or any
        // existing queued transactions still waiting to be processed.
        if ($result['synced'] > 0 || Transaction::query()->where('status', 'queued')->exists()) {
            Log::debug('Bingwa transaction sync job dispatching queued processor.', [
                'user_id' => $this->userId,
            ]);

            $dispatchBingwaQueuedTransactionsJob->dispatch($this->userId);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Bingwa transaction sync job marked as failed.', [
            'user_id' => $this->userId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
