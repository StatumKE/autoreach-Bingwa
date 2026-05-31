<?php

namespace App\Actions\Autoreach;

use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\DeviceSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DispatchBingwaQueuedTransactionsJob
{
    public function dispatch(?int $userId = null): bool
    {
        $user = User::query()->first();
        $userId = $user ? $user->id : 0;

        if ($userId === 0) {
            return false;
        }

        if (! DeviceSetting::isTransactionProcessingEnabledForUser($userId)) {
            Log::debug('Bingwa transaction processing dispatch skipped because processing is paused.', [
                'user_id' => $userId,
            ]);

            return false;
        }

        ProcessBingwaQueuedTransactionsJob::dispatch((string) Str::uuid(), $userId);

        return true;
    }
}
