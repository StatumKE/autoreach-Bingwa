<?php

namespace App\Jobs;

use App\Actions\Autoreach\SendBingwaHeartbeat;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendHeartbeatJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct() {}

    public function uniqueId(): string
    {
        return 'bingwa-send-heartbeat';
    }

    /**
     * Execute the job.
     */
    public function handle(SendBingwaHeartbeat $sendBingwaHeartbeat): void
    {
        $user = User::query()->first();

        if (! $user instanceof User) {
            Log::warning('Bingwa heartbeat job skipped because no user was found.');

            return;
        }

        Log::debug('Bingwa heartbeat job started.', [
            'user_id' => $user->id,
        ]);

        if ($user->bingwaDeviceRegistration === null) {
            Log::debug('Bingwa heartbeat job skipped because user has no device registration.', [
                'user_id' => $user->id,
            ]);

            return;
        }

        $sent = $sendBingwaHeartbeat->send($user);

        Log::debug('Bingwa heartbeat job finished.', [
            'user_id' => $user->id,
            'sent' => $sent,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $user = User::query()->first();
        Log::error('Bingwa heartbeat job marked as failed.', [
            'user_id' => $user?->id,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }
}
