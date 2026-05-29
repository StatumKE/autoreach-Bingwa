<?php

namespace App\Services;

use App\Actions\Autoreach\SendBingwaHeartbeat;
use App\Models\BingwaDeviceRegistration;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AndroidRuntimeScheduler
{
    public const RUN_LOCK_KEY = 'bingwa:android-runtime-scheduler:running';

    public const TRANSACTION_SYNC_KEY = 'bingwa:android-runtime-scheduler:transaction-sync';

    private const TICK_SECONDS = 900; // 15 minutes

    public function __construct(
        private SendBingwaHeartbeat $sendBingwaHeartbeat,
    ) {}

    public static function transactionSyncKey(int $userId): string
    {
        return self::TRANSACTION_SYNC_KEY.':'.$userId;
    }

    /**
     * @return array{ran: bool, heartbeat: bool, transaction_sync: bool, next_tick_seconds: int}
     */
    public function runDueTasks(?CarbonInterface $now = null): array
    {
        $currentTime = $now ?? now();
        $lock = Cache::lock(self::RUN_LOCK_KEY, 30);

        Log::debug('Bingwa runtime tick started.', [
            'current_time' => $currentTime->toIso8601String(),
            'lock_key' => self::RUN_LOCK_KEY,
        ]);

        if (! $lock->get()) {
            Log::debug('Bingwa runtime tick skipped — already running.', [
                'current_time' => $currentTime->toIso8601String(),
                'lock_key' => self::RUN_LOCK_KEY,
            ]);

            return $this->result(ran: false, heartbeat: false, transactionSync: false, nextTickSeconds: 60);
        }

        try {
            $user = $this->registeredUser();

            if ($user === null) {
                Log::debug('Bingwa runtime tick skipped — no registered user found.', [
                    'current_time' => $currentTime->toIso8601String(),
                ]);

                return $this->result(ran: false, heartbeat: false, transactionSync: false);
            }

            $heartbeat = false;
            $registration = $user->bingwaDeviceRegistration;
            $heartbeatDue = false;
            if ($registration) {
                $lastSeen = $registration->last_seen_at;
                $heartbeatDue = $lastSeen === null || $lastSeen->lt($currentTime->copy()->subMinutes(5));
                if ($heartbeatDue) {
                    $heartbeat = $this->sendBingwaHeartbeat->send($user);
                }
            }

            $transactionSync = false;
            $transactionSyncKey = self::transactionSyncKey($user->getKey());
            $lastSyncStr = Cache::get($transactionSyncKey);
            $transactionSyncDue = $lastSyncStr === null || Carbon::parse($lastSyncStr)->lt($currentTime->copy()->subMinutes(5));
            if ($transactionSyncDue) {
                Log::debug('Bingwa runtime tick executing transaction sync command.', [
                    'user_id' => $user->getKey(),
                    'last_sync_at' => $lastSyncStr,
                ]);

                $exitCode = Artisan::call('bingwa:sync-transactions');
                $transactionSync = $exitCode === 0;
                Log::debug('Bingwa runtime tick transaction sync command finished.', [
                    'user_id' => $user->getKey(),
                    'exit_code' => $exitCode,
                    'output' => Artisan::output(),
                ]);
                if ($transactionSync) {
                    Cache::forever($transactionSyncKey, $currentTime->toIso8601String());
                }
            } else {
                Log::debug('Bingwa runtime tick skipped transaction sync due to recent run.', [
                    'user_id' => $user->getKey(),
                    'last_sync_at' => $lastSyncStr,
                ]);
            }

            Log::debug('Bingwa runtime tick executing auto renewal command.', [
                'user_id' => $user->getKey(),
            ]);

            $autoRenewalExitCode = Artisan::call('bingwa:process-auto-renewals');

            $nextTickSeconds = $this->nextTickSeconds($user->getKey(), $currentTime);

            Log::info('Bingwa runtime tick completed.', [
                'user_id' => $user->getKey(),
                'heartbeat_due' => $heartbeatDue,
                'heartbeat' => $heartbeat,
                'transaction_sync_due' => $transactionSyncDue,
                'transaction_sync' => $transactionSync,
                'auto_renewals' => $autoRenewalExitCode === 0,
                'next_tick_seconds' => $nextTickSeconds,
            ]);

            return $this->result(
                ran: true,
                heartbeat: $heartbeat,
                transactionSync: $transactionSync,
                nextTickSeconds: $nextTickSeconds
            );
        } finally {
            $lock->release();
        }
    }

    private function registeredUser(): ?User
    {
        $registration = BingwaDeviceRegistration::query()
            ->whereNotNull('device_token')
            ->where('device_token', '!=', '')
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', '!=', 'stopped');
            })
            ->first();

        if (! $registration) {
            return null;
        }

        $user = User::query()->first();

        if ($user) {
            $user->setRelation('bingwaDeviceRegistration', $registration);

            return $user;
        }

        return null;
    }

    /**
     * @return array{ran: bool, heartbeat: bool, transaction_sync: bool, next_tick_seconds: int}
     */
    private function result(bool $ran, bool $heartbeat, bool $transactionSync, ?int $nextTickSeconds = null): array
    {
        return [
            'ran' => $ran,
            'heartbeat' => $heartbeat,
            'transaction_sync' => $transactionSync,
            'next_tick_seconds' => $nextTickSeconds ?? self::TICK_SECONDS,
        ];
    }

    private function nextTickSeconds(int $userId, CarbonInterface $currentTime): int
    {
        $hasQueued = Transaction::query()
            ->where('user_id', $userId)
            ->where('status', 'queued')
            ->where(function ($query) use ($currentTime): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $currentTime);
            })
            ->exists();

        return $hasQueued ? 15 : self::TICK_SECONDS;
    }
}
