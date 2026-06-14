<?php

namespace App\Actions\Autoreach;

use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RecoverBingwaDeviceToken
{
    /**
     * Recover and persist the Bingwa device token for an existing registration.
     */
    public function recover(User $user): BingwaDeviceRegistration
    {
        $registration = $user->bingwaDeviceRegistration;

        if (! $registration instanceof BingwaDeviceRegistration) {
            throw ValidationException::withMessages([
                'bhc_code' => __('No registered Bingwa device was found for this account.'),
            ]);
        }

        if (! filled($registration->hardware_id) || ! filled($registration->bhc_code)) {
            throw ValidationException::withMessages([
                'bhc_code' => __('The saved device registration is incomplete.'),
            ]);
        }

        $originalToken = $registration->device_token;

        $lockKey = 'bingwa_device_token_recovery_lock_'.$user->id;
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (! cache()->has($lockKey)) {
                break;
            }

            // Wait for the other request to finish recovery
            usleep(500000); // 500ms

            // Re-read registration from DB to see if the token changed
            $registration = $registration->fresh();
            if ($registration && $registration->device_token !== $originalToken && filled($registration->device_token)) {
                return $registration;
            }
        }

        $cooldownKey = 'bingwa_device_token_recovery_cooldown_'.$user->id;
        if (cache()->has($cooldownKey)) {
            throw ValidationException::withMessages([
                'bhc_code' => __('Device token recovery is on cooldown. Please wait before retrying.'),
            ]);
        }

        // Acquire lock
        cache()->put($lockKey, true, 20);

        try {
            try {
                // Re-check token inside lock
                $registration = $registration->fresh();
                if ($registration && $registration->device_token !== $originalToken && filled($registration->device_token)) {
                    return $registration;
                }

                $response = Http::baseUrl(rtrim((string) config('services.autoreach.backend_url'), '/'))
                    ->retry(3, 100, function (\Throwable $exception): bool {
                        return $exception instanceof ConnectionException;
                    }, throw: false)
                    ->acceptJson()
                    ->asJson()
                    ->timeout(30)
                    ->post('/api/v1/auth/device/token/recover', [
                        'email' => $user->email,
                        'hardware_id' => $registration->hardware_id,
                        'bhc_code' => $registration->bhc_code,
                    ]);

                if ($response->successful()) {
                    return $this->persistRecoveredToken($registration, $response);
                }

                if ($response->status() === 422) {
                    $errors = $response->json('errors');
                    $message = $response->json('message') ?? '';
                    $isNotFound = (is_array($errors) && (
                        str_contains(implode(' ', $errors['bhc_code'] ?? []), 'not found') ||
                        str_contains(implode(' ', $errors['email'] ?? []), 'not found')
                    )) || str_contains($message, 'not found');

                    if ($isNotFound) {
                        Log::warning("Backend device not found during recovery (possibly DB reset). Attempting automatic re-registration for user {$user->id}.");
                        try {
                            $registerService = app(RegisterBingwaDevice::class);
                            $backendRegistration = $registerService->registerOnBackend($user);

                            return $registerService->persistRegistration($user, $backendRegistration);
                        } catch (\Throwable $registrationException) {
                            Log::error("Automatic re-registration failed: {$registrationException->getMessage()}");
                        }
                    }
                }

                cache()->put($cooldownKey, true, 60);
                $this->throwValidationException($response);
            } catch (\Throwable $exception) {
                cache()->put($cooldownKey, true, 60);
                throw $exception;
            }
        } finally {
            cache()->forget($lockKey);
        }
    }

    /**
     * Persist the recovered device token locally.
     */
    private function persistRecoveredToken(BingwaDeviceRegistration $registration, Response $response): BingwaDeviceRegistration
    {
        $deviceToken = $response->json('device_token');
        $deviceId = $response->json('device_id');
        $bhcCode = $response->json('bhc_code');

        if (! is_string($deviceToken) || $deviceToken === '') {
            throw ValidationException::withMessages([
                'bhc_code' => __('The backend returned an incomplete recovery response.'),
            ]);
        }

        $registration->forceFill([
            'device_token' => $deviceToken,
            'backend_device_id' => is_numeric($deviceId) ? (int) $deviceId : $registration->backend_device_id,
            'bhc_code' => is_string($bhcCode) && $bhcCode !== '' ? $bhcCode : $registration->bhc_code,
        ])->save();

        if ($registration->user !== null) {
            app(FetchBingwaSubscriptionPlans::class)->forget($registration->user);
        }

        return $registration->refresh();
    }

    private function throwValidationException(Response $response): never
    {
        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        throw ValidationException::withMessages([
            'bhc_code' => $response->json('message') ?? __('Unable to recover the device token right now.'),
        ]);
    }
}
