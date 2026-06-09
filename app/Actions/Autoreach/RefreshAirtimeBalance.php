<?php

namespace App\Actions\Autoreach;

use App\Exceptions\UssdModemBusyException;
use App\Models\DeviceSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RefreshAirtimeBalance
{
    /**
     * Refresh and persist the latest airtime balance for the user's primary transaction SIM.
     *
     * @return array{balance: float|null, raw_response: string|null, checked_at: Carbon|null, permission_denied: bool}
     */
    public function refresh(User $user): array
    {
        Log::info('Bingwa airtime balance refresh started.', [
            'component' => 'airtime_balance',
            'user_id' => $user->id,
        ]);

        $settings = $this->ensureSettings($user);

        $simSlot = $settings->primary_transaction_sim === 'slot_2' ? 1 : 0;
        $preferredMode = $settings->app_interface_mode ?? 'express';

        $response = $this->executeBalanceQuery(
            $simSlot,
            (int) ($settings->ussd_timeout_seconds ?? 60),
            $preferredMode
        );

        $balance = $response !== null ? $this->parseBalance($response) : null;

        if ($balance === null && $preferredMode === 'express') {
            Log::info('Express airtime USSD query did not yield a valid balance; attempting advanced mode fallback.', [
                'component' => 'airtime_balance',
                'user_id' => $user->id,
                'sim_slot' => $simSlot,
                'raw_response' => $response,
            ]);

            $fallbackResponse = $this->executeBalanceQuery(
                $simSlot,
                (int) ($settings->ussd_timeout_seconds ?? 60),
                'advanced'
            );

            if ($fallbackResponse !== null) {
                $fallbackBalance = $this->parseBalance($fallbackResponse);
                if ($fallbackBalance !== null) {
                    $response = $fallbackResponse;
                    $balance = $fallbackBalance;
                }
            }
        }

        if ($balance === null || $response === null) {
            Log::warning('Bingwa airtime balance refresh failed: Could not retrieve or parse balance.', [
                'component' => 'airtime_balance',
                'user_id' => $user->id,
                'raw_response' => $response,
            ]);

            return $this->cached($user);
        }

        $checkedAt = now();

        $deviceSettings = DeviceSetting::query()->firstOrNew([
            'user_id' => $user->id,
        ]);

        $deviceSettings->forceFill([
            'airtime_balance' => $balance,
            'airtime_balance_raw_response' => $response,
            'airtime_balance_checked_at' => $checkedAt,
        ]);
        $deviceSettings->save();

        // Refresh the in-memory relation so subsequent cached() calls on the same
        // user object return the newly saved values, not the stale Eloquent instance.
        $user->setRelation('deviceSetting', $deviceSettings);

        Log::debug('Bingwa airtime balance refreshed.', [
            'component' => 'airtime_balance',
            'user_id' => $user->id,
            'sim_slot' => $simSlot,
            'balance' => $balance,
        ]);

        return [
            'balance' => $balance,
            'raw_response' => $response,
            'checked_at' => $checkedAt,
            'permission_denied' => false,
        ];
    }

    /**
     * Get the current persisted airtime snapshot for the user.
     *
     * @return array{balance: float|null, raw_response: string|null, checked_at: Carbon|null, permission_denied: bool}
     */
    public function cached(User $user): array
    {
        $deviceSettings = $this->ensureSettings($user);

        $rawResponse = is_string($deviceSettings->airtime_balance_raw_response)
            ? $deviceSettings->airtime_balance_raw_response
            : null;

        $permissionDenied = $rawResponse !== null && $this->isPermissionErrorResponse($rawResponse);

        // Do not surface a stale permission-error string as a valid raw response.
        if ($permissionDenied) {
            $rawResponse = null;
        }

        return [
            'balance' => is_numeric($deviceSettings->airtime_balance) ? (float) $deviceSettings->airtime_balance : null,
            'raw_response' => $rawResponse,
            'checked_at' => $deviceSettings->airtime_balance_checked_at instanceof Carbon
                ? $deviceSettings->airtime_balance_checked_at
                : ($deviceSettings->airtime_balance_checked_at !== null
                    ? Carbon::parse($deviceSettings->airtime_balance_checked_at)
                    : null),
            'permission_denied' => $permissionDenied,
        ];
    }

    /**
     * Determine whether a stored raw response looks like a native bridge permission error.
     */
    public function isPermissionErrorResponse(string $rawResponse): bool
    {
        return stripos($rawResponse, 'permission') !== false
            || stripos($rawResponse, 'not granted') !== false;
    }

    private function executeBalanceQuery(int $simSlot, int $timeoutSeconds, string $mode = 'express'): ?string
    {
        $nativephpAvailable = function_exists('nativephp_call');

        Log::debug('Bingwa airtime USSD probe.', [
            'component' => 'airtime_balance',
            'sim_slot' => $simSlot,
            'mode' => $mode,
            'nativephp_call_available' => $nativephpAvailable,
        ]);

        if (! $nativephpAvailable) {
            return null;
        }

        $payload = json_encode([
            'id' => -1, // Use a negative/dummy ID to bypass non-null async check (though we want it sync)
            'code' => '*144#',
            'mode' => $mode,
            'simSlot' => $simSlot,
            'timeoutSeconds' => max(1, $timeoutSeconds),
            'runAsync' => false,
        ]);

        try {
            $rawResponse = app(UssdModemLock::class)->run(
                callback: fn (): mixed => nativephp_call('ExecuteUssd', $payload),
                operation: 'airtime-refresh',
                waitSeconds: 2,
                leaseSeconds: max(1, $timeoutSeconds) + 15,
                context: [
                    'sim_slot' => $simSlot,
                ],
            );
        } catch (UssdModemBusyException $exception) {
            Log::info('Bingwa airtime balance refresh skipped because the modem is busy.', [
                'component' => 'airtime_balance',
                'sim_slot' => $simSlot,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        Log::debug('Bingwa airtime USSD raw response.', [
            'component' => 'airtime_balance',
            'sim_slot' => $simSlot,
            'response' => $rawResponse,
        ]);

        if (! is_string($rawResponse) || $rawResponse === '') {
            return null;
        }

        $decoded = json_decode($rawResponse, true);

        if (! is_array($decoded)) {
            return null;
        }

        // Detect native bridge error payloads (e.g. permission denied, timeout).
        $error = $decoded['error'] ?? $decoded['data']['error'] ?? null;
        if (is_string($error) && $error !== '') {
            Log::warning('Bingwa airtime USSD returned a bridge error.', [
                'component' => 'airtime_balance',
                'sim_slot' => $simSlot,
                'error' => $error,
            ]);

            return null;
        }

        $message = $decoded['data']['message'] ?? $decoded['message'] ?? null;

        // Guard against permission / error strings being treated as USSD responses.
        if (! is_string($message) || $message === '') {
            return null;
        }

        if (stripos($message, 'permission') !== false || stripos($message, 'not granted') !== false) {
            Log::warning('Bingwa airtime USSD message looks like a permission error; ignoring.', [
                'component' => 'airtime_balance',
                'sim_slot' => $simSlot,
                'message' => $message,
            ]);

            return null;
        }

        Log::debug('Bingwa airtime USSD message extracted.', [
            'component' => 'airtime_balance',
            'sim_slot' => $simSlot,
            'message' => $message,
        ]);

        return $message;
    }

    private function parseBalance(string $response): ?float
    {
        // "Airtime Bal: 13.44KSH" / "Airtime Balance: 13.44"
        if (preg_match('/Airtime\s*Bal[^0-9]*([0-9]+(?:\.[0-9]+)?)/i', $response, $matches) === 1) {
            Log::debug('Bingwa airtime balance parsed via Airtime Bal pattern.', ['component' => 'airtime_balance', 'raw' => $response, 'parsed' => $matches[1]]);

            return (float) $matches[1];
        }

        // "13.44 KSH" or "13.44KSH"
        if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\s*KSH\b/i', $response, $matches) === 1) {
            Log::debug('Bingwa airtime balance parsed via KSH suffix pattern.', ['component' => 'airtime_balance', 'raw' => $response, 'parsed' => $matches[1]]);

            return (float) $matches[1];
        }

        // "KSh 13.44" or "KSh.13.44" (KSh prefix, common in Safaricom responses)
        if (preg_match('/KSh\.?\s*([0-9]+(?:\.[0-9]+)?)/i', $response, $matches) === 1) {
            Log::debug('Bingwa airtime balance parsed via KSh prefix pattern.', ['component' => 'airtime_balance', 'raw' => $response, 'parsed' => $matches[1]]);

            return (float) $matches[1];
        }

        Log::warning('Bingwa airtime balance could not be parsed from USSD response.', ['component' => 'airtime_balance', 'raw' => $response]);

        return null;
    }

    private function ensureSettings(User $user): DeviceSetting
    {
        // Bypass the in-memory cached relationship on the User instance (which could be stale in a persistent PHP daemon context)
        // by performing a fresh query to retrieve the current setting from the database.
        $settings = DeviceSetting::query()->where('user_id', $user->id)->first();

        if ($settings !== null) {
            $user->setRelation('deviceSetting', $settings);

            return $settings;
        }

        $settings = DeviceSetting::query()->firstOrCreate([
            'user_id' => $user->id,
        ], [
            'operator_identity' => $user->name,
            'primary_transaction_sim' => 'slot_1',
            'sms_auto_reply_sim' => 'slot_1',
            'app_interface_mode' => 'express',
            'auto_reschedule_rejected' => true,
            'retry_tomorrow_at' => '12:30 AM',
            'ussd_timeout_seconds' => 90,
            'intelligent_auto_retry' => true,
            'retry_interval_minutes' => 1,
            'max_attempts' => 2,
            'retry_network_issues' => true,
        ]);

        $user->setRelation('deviceSetting', $settings);

        return $settings;
    }
}
