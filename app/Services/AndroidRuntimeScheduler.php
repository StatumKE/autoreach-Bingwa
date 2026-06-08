<?php

namespace App\Services;

use App\Actions\Autoreach\FetchBingwaSubscriptionPlans;
use App\Actions\Autoreach\SendBingwaHeartbeat;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AndroidRuntimeScheduler
{
    public const RUN_LOCK_KEY = 'bingwa:android-runtime-scheduler:running';

    public const TRANSACTION_SYNC_KEY = 'bingwa:android-runtime-scheduler:transaction-sync';

    public const PLANS_PREFETCH_KEY = 'bingwa:android-runtime-scheduler:plans-prefetch';

    private const TICK_SECONDS = 900; // 15 minutes

    public function __construct(
        private SendBingwaHeartbeat $sendBingwaHeartbeat,
        private BingwaDeviceContext $deviceContext,
    ) {}

    public static function transactionSyncKey(int $userId): string
    {
        return self::TRANSACTION_SYNC_KEY.':'.$userId;
    }

    public static function plansPrefetchKey(int $userId): string
    {
        return self::PLANS_PREFETCH_KEY.':'.$userId;
    }

    /**
     * @return array{ran: bool, heartbeat: bool, transaction_sync: bool, next_tick_seconds: int}
     */
    public function runDueTasks(?CarbonInterface $now = null): array
    {
        $currentTime = $now ?? now();
        $lock = Cache::lock(self::RUN_LOCK_KEY, 30);

        Log::debug('Bingwa runtime tick started.', [
            'component' => 'runtime_scheduler',
            'current_time' => $currentTime->toIso8601String(),
            'lock_key' => self::RUN_LOCK_KEY,
        ]);

        if (! $lock->get()) {
            Log::debug('Bingwa runtime tick skipped — already running.', [
                'component' => 'runtime_scheduler',
                'current_time' => $currentTime->toIso8601String(),
                'lock_key' => self::RUN_LOCK_KEY,
            ]);

            return $this->result(ran: false, heartbeat: false, transactionSync: false, nextTickSeconds: 60);
        }

        try {
            $user = $this->deviceContext->user();

            if ($user === null) {
                Log::debug('Bingwa runtime tick skipped — no registered user found.', [
                    'component' => 'runtime_scheduler',
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
                Log::debug('Bingwa runtime tick heartbeat evaluation completed.', [
                    'component' => 'runtime_scheduler',
                    'user_id' => $user->getKey(),
                    'registration_id' => $registration->getKey(),
                    'last_seen_at' => $lastSeen?->toIso8601String(),
                    'heartbeat_due' => $heartbeatDue,
                ]);
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
                    'component' => 'runtime_scheduler',
                    'user_id' => $user->getKey(),
                    'last_sync_at' => $lastSyncStr,
                ]);

                $exitCode = Artisan::call('bingwa:sync-transactions');
                $transactionSync = $exitCode === 0;
                Log::debug('Bingwa runtime tick transaction sync command finished.', [
                    'component' => 'runtime_scheduler',
                    'user_id' => $user->getKey(),
                    'exit_code' => $exitCode,
                    'output' => Artisan::output(),
                ]);
                if ($transactionSync) {
                    Cache::forever($transactionSyncKey, $currentTime->toIso8601String());
                }
            } else {
                Log::debug('Bingwa runtime tick skipped transaction sync due to recent run.', [
                    'component' => 'runtime_scheduler',
                    'user_id' => $user->getKey(),
                    'last_sync_at' => $lastSyncStr,
                ]);
            }

            $plansPrefetchKey = self::plansPrefetchKey($user->getKey());
            $lastPlansPrefetchStr = Cache::get($plansPrefetchKey);
            $plansPrefetchDue = $lastPlansPrefetchStr === null || Carbon::parse($lastPlansPrefetchStr)->lt($currentTime->copy()->subMinutes(60));
            if ($plansPrefetchDue) {
                Log::debug('Bingwa runtime tick prefetching subscription plans.', [
                    'component' => 'runtime_scheduler',
                    'user_id' => $user->getKey(),
                    'last_prefetch_at' => $lastPlansPrefetchStr,
                ]);

                try {
                    app(FetchBingwaSubscriptionPlans::class)->fetch($user);
                    Cache::forever($plansPrefetchKey, $currentTime->toIso8601String());
                    Log::debug('Bingwa runtime tick prefetch completed.', [
                        'component' => 'runtime_scheduler',
                        'user_id' => $user->getKey(),
                    ]);
                } catch (\Throwable $throwable) {
                    Log::warning('Failed to prefetch subscription plans in AndroidRuntimeScheduler.', [
                        'component' => 'runtime_scheduler',
                        'user_id' => $user->getKey(),
                        'error' => $throwable->getMessage(),
                    ]);
                }
            }

            Log::debug('Bingwa runtime tick executing auto renewal command.', [
                'component' => 'runtime_scheduler',
                'user_id' => $user->getKey(),
            ]);

            $autoRenewalExitCode = Artisan::call('bingwa:process-auto-renewals');

            $nextTickSeconds = $this->nextTickSeconds($user->getKey(), $currentTime);

            Log::info('Bingwa runtime tick completed.', [
                'component' => 'runtime_scheduler',
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
            ->where(function ($query) use ($currentTime): void {
                $query->where(function ($q) use ($currentTime) {
                    $q->where('status', 'queued')
                        ->where(function ($sq) use ($currentTime) {
                            $sq->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', $currentTime);
                        });
                })->orWhere(function ($q) use ($currentTime) {
                    $q->where('status', 'failed')
                        ->whereNotNull('next_attempt_at')
                        ->where('next_attempt_at', '<=', $currentTime);
                });
            })
            ->exists();

        return $hasQueued ? 15 : self::TICK_SECONDS;
    }
}
