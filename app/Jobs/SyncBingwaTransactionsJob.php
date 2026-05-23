<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
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

    public function handle(): void
    {
        Log::debug('Bingwa transaction sync job started.', [
            'user_id' => $this->userId,
            'push_data' => $this->pushData,
        ]);

        Log::debug('Bingwa transaction sync job invoking artisan command.', [
            'user_id' => $this->userId,
            'command' => 'bingwa:sync-transactions',
        ]);

        $exitCode = Artisan::call('bingwa:sync-transactions', [
            '--user-id' => $this->userId,
        ]);

        Log::debug('Bingwa transaction sync job finished.', [
            'user_id' => $this->userId,
            'artisan_exit_code' => $exitCode,
            'artisan_output' => Artisan::output(),
        ]);
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
