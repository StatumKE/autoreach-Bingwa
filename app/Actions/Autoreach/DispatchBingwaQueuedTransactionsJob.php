<?php

namespace App\Actions\Autoreach;

use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\DeviceSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DispatchBingwaQueuedTransactionsJob
{
    public function dispatch(int $userId): bool
    {
        if (! DeviceSetting::isTransactionProcessingEnabledForUser($userId)) {
            Log::debug('Bingwa transaction processing dispatch skipped because processing is paused.', [
                'user_id' => $userId,
            ]);

            return false;
        }

        ProcessBingwaQueuedTransactionsJob::dispatch($userId, (string) Str::uuid());
        Log::debug('Bingwa transaction processing job dispatched.', [
            'user_id' => $userId,
        ]);

        return true;
    }
}
