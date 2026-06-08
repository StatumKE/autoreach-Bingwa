<?php

namespace App\Actions\Autoreach;

use App\Exceptions\UssdModemBusyException;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UssdModemLock
{
    public const KEY = 'bingwa:ussd-modem';

    /**
     * Run one native USSD operation while this device runtime owns the modem.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws UssdModemBusyException
     */
    public function run(
        Closure $callback,
        string $operation,
        int $waitSeconds = 0,
        int $leaseSeconds = 45,
        array $context = [],
    ): mixed {
        $lock = Cache::lock(self::KEY, max(1, $leaseSeconds));
        $acquired = false;

        try {
            if ($waitSeconds > 0) {
                $lock->block($waitSeconds);
                $acquired = true;
            } else {
                $acquired = $lock->get();
            }
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        if (! $acquired) {
            Log::info('USSD modem lock busy.', [
                'component' => 'ussd_modem',
                ...$context,
                'operation' => $operation,
                'wait_seconds' => $waitSeconds,
                'lease_seconds' => $leaseSeconds,
            ]);

            throw new UssdModemBusyException(__('Another USSD session is already in progress. Try again shortly.'));
        }

        Log::debug('USSD modem lock acquired.', [
            'component' => 'ussd_modem',
            ...$context,
            'operation' => $operation,
            'wait_seconds' => $waitSeconds,
            'lease_seconds' => $leaseSeconds,
        ]);

        try {
            return $callback();
        } finally {
            $lock->release();

            Log::debug('USSD modem lock released.', [
                'component' => 'ussd_modem',
                ...$context,
                'operation' => $operation,
            ]);
        }
    }
}
