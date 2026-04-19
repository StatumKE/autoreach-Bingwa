<?php

namespace App\Actions\Autoreach;

use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Native\Mobile\Facades\Device;

class RegisterBingwaDevice
{
    /**
     * Determine whether the current device already has a Bingwa registration.
     */
    public function isCurrentDeviceRegistered(): bool
    {
        $hardwareId = $this->resolveHardwareId();

        return BingwaDeviceRegistration::query()
            ->where('hardware_id', $hardwareId)
            ->exists();
    }

    /**
     * Register the current device with the Autoreach backend.
     */
    public function registerOnBackend(User $user): array
    {
        $hardwareId = $this->resolveHardwareId();

        $payload = [
            'hardware_id' => $hardwareId,
            'device_type' => 'hybridApp',
            'email' => $user->email,
            'connect_device_id' => $user->autoreach_connect_id,
            'app_version' => config('nativephp.version', '1.0.0'),
        ];

        $deviceInfo = $this->deviceInfo($hardwareId);

        if ($deviceInfo !== []) {
            $payload['device_info'] = $deviceInfo;
        }

        $response = Http::baseUrl(rtrim((string) config('services.autoreach.backend_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post('/api/v1/auth/device/register/hybrid', $payload);

        if ($response->successful()) {
            return array_merge(
                $response->json() ?? [],
                ['hardware_id' => $hardwareId],
            );
        }

        $this->throwValidationException($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceInfo(string $hardwareId): array
    {
        $deviceInfo = Device::getInfo();

        if (! is_string($deviceInfo) || $deviceInfo === '') {
            return [];
        }

        $decoded = json_decode($deviceInfo, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_filter([
            'name' => $decoded['name'] ?? null,
            'model' => $decoded['model'] ?? null,
            'manufacturer' => $decoded['manufacturer'] ?? null,
            'os_name' => $decoded['operatingSystem'] ?? $decoded['os_name'] ?? null,
            'os_version' => $decoded['osVersion'] ?? $decoded['os_version'] ?? null,
            'platform' => $decoded['platform'] ?? null,
            'is_virtual' => $decoded['isVirtual'] ?? $decoded['is_virtual'] ?? null,
            'uuid' => $hardwareId,
        ], static fn ($value): bool => $value !== null);
    }

    private function throwValidationException(Response $response): never
    {
        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        throw ValidationException::withMessages([
            'autoreach_connect_id' => $response->json('message') ?? __('Unable to register this device right now.'),
        ]);
    }

    /**
     * Persist the backend registration details locally.
     */
    public function persistRegistration(User $user, array $backendRegistration): BingwaDeviceRegistration
    {
        $hardwareId = $backendRegistration['hardware_id'] ?? $this->resolveHardwareId();

        $deviceToken = $backendRegistration['device_token'] ?? null;
        $bhcCode = $backendRegistration['bhc_code'] ?? null;
        $backendDeviceId = $backendRegistration['device_id'] ?? null;

        if (! is_string($deviceToken) || $deviceToken === '' || ! is_string($bhcCode) || $bhcCode === '') {
            throw ValidationException::withMessages([
                'autoreach_connect_id' => __('The backend returned an incomplete device registration response.'),
            ]);
        }

        $deviceInfo = $this->deviceInfo($hardwareId);

        $record = BingwaDeviceRegistration::updateOrCreate(
            ['hardware_id' => $hardwareId],
            [
                'user_id' => $user->id,
                'hardware_id' => $hardwareId,
                'device_token' => $deviceToken,
                'bhc_code' => $bhcCode,
                'backend_device_id' => is_numeric($backendDeviceId) ? (int) $backendDeviceId : null,
                'app_type' => $backendRegistration['app_type'] ?? 'hybridApp',
                'backend_device_type' => $backendRegistration['backend_device_type'] ?? null,
                'connect_device_id' => $backendRegistration['connect_device_id'] ?? $user->autoreach_connect_id,
                'linked_connect_device_id' => $backendRegistration['linked_connect_device_id'] ?? null,
                'device_name' => $deviceInfo['name'] ?? null,
                'app_version' => $backendRegistration['app_version'] ?? config('nativephp.version', '1.0.0'),
                'device_info' => $deviceInfo !== [] ? $deviceInfo : null,
                'metadata' => $backendRegistration['metadata'] ?? null,
            ],
        );

        return $record;
    }

    private function resolveHardwareId(): string
    {
        $hardwareId = Device::getId();

        if (is_string($hardwareId) && $hardwareId !== '') {
            return $hardwareId;
        }

        return cache()->rememberForever('autoreach.hardware_id', function (): string {
            return (string) Str::uuid();
        });
    }
}
