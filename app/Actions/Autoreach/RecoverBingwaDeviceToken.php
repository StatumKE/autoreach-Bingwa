<?php

namespace App\Actions\Autoreach;

use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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

        $response = Http::baseUrl(rtrim((string) config('services.autoreach.backend_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post('/api/v1/auth/device/token/recover', [
                'email' => $user->email,
                'hardware_id' => $registration->hardware_id,
                'bhc_code' => $registration->bhc_code,
            ]);

        if ($response->successful()) {
            return $this->persistRecoveredToken($registration, $response);
        }

        $this->throwValidationException($response);
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
