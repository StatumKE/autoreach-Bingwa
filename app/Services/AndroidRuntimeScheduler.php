<?php

namespace App\Services;

use App\Actions\Autoreach\SendBingwaHeartbeat;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AndroidRuntimeScheduler
{
    private const RUN_LOCK_KEY = 'bingwa:android-runtime-scheduler:running';

    private const TICK_SECONDS = 900; // 15 minutes

    public function __construct(
        private SendBingwaHeartbeat $sendBingwaHeartbeat,
    ) {}

    /**
     * @return array{ran: bool, heartbeat: bool, transaction_sync: bool, next_tick_seconds: int}
     */
    public function runDueTasks(): array
    {
        $lock = Cache::lock(self::RUN_LOCK_KEY, 30);

        if (! $lock->get()) {
            Log::debug('Bingwa runtime tick skipped — already running.');

            return $this->result(ran: false, heartbeat: false, transactionSync: false);
        }

        try {
            $user = $this->registeredUser();

            if ($user === null) {
                Log::debug('Bingwa runtime tick skipped — no registered user found.');

                return $this->result(ran: false, heartbeat: false, transactionSync: false);
            }

            $heartbeat = $this->sendBingwaHeartbeat->send($user);

            $exitCode = Artisan::call('bingwa:sync-transactions', [
                '--user-id' => $user->getKey(),
            ]);

            $transactionSync = $exitCode === 0;

            Log::info('Bingwa runtime tick completed.', [
                'user_id' => $user->getKey(),
                'heartbeat' => $heartbeat,
                'transaction_sync' => $transactionSync,
            ]);

            return $this->result(ran: true, heartbeat: $heartbeat, transactionSync: $transactionSync);
        } finally {
            $lock->release();
        }
    }

    private function registeredUser(): ?User
    {
        return User::query()
            ->with('bingwaDeviceRegistration')
            ->whereHas('bingwaDeviceRegistration', function ($query): void {
                $query->whereNotNull('device_token')
                    ->where('device_token', '!=', '')
                    ->where(function ($query): void {
                        $query->whereNull('status')
                            ->orWhere('status', '!=', 'stopped');
                    });
            })
            ->first();
    }

    /**
     * @return array{ran: bool, heartbeat: bool, transaction_sync: bool, next_tick_seconds: int}
     */
    private function result(bool $ran, bool $heartbeat, bool $transactionSync): array
    {
        return [
            'ran' => $ran,
            'heartbeat' => $heartbeat,
            'transaction_sync' => $transactionSync,
            'next_tick_seconds' => self::TICK_SECONDS,
        ];
    }
}
