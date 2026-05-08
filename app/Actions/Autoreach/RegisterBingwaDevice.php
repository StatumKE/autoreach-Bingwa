<?php

namespace App\Actions\Autoreach;

use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Native\Mobile\Facades\Device;

class RegisterBingwaDevice
{
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
        $payload['device_info'] = $deviceInfo;

        Log::debug('Submitting Bingwa device registration request.', [
            'url' => rtrim((string) config('services.autoreach.backend_url'), '/').'/api/v1/auth/device/register/hybrid',
            'payload' => $payload,
        ]);

        $response = Http::baseUrl(rtrim((string) config('services.autoreach.backend_url'), '/'))
            ->retry(3, 100, throw: false)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post('/api/v1/auth/device/register/hybrid', $payload);

        Log::debug('Bingwa device registration response received.', [
            'status' => $response->status(),
        ]);

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
        Log::debug('Bingwa native device info bridge probe.', [
            'hardware_id' => $hardwareId,
            'nativephp_call_available' => function_exists('nativephp_call'),
        ]);

        $rawDeviceInfo = $this->nativeBridgeCall('Device.GetInfo', '{}');

        Log::debug('Bingwa native device info raw response.', [
            'hardware_id' => $hardwareId,
            'response' => is_string($rawDeviceInfo) ? $rawDeviceInfo : null,
        ]);

        $deviceInfo = $this->decodeNativeBridgeDeviceInfo($rawDeviceInfo, $hardwareId);

        if ($deviceInfo !== []) {
            return $deviceInfo;
        }

        $deviceInfo = Device::getInfo();

        if (! is_string($deviceInfo) || $deviceInfo === '') {
            Log::debug('Bingwa native device info bridge returned an empty value.', [
                'hardware_id' => $hardwareId,
            ]);

            return [
                'uuid' => $hardwareId,
            ];
        }

        $decoded = json_decode($deviceInfo, true);

        if (! is_array($decoded)) {
            Log::debug('Bingwa native device info bridge returned invalid JSON.', [
                'hardware_id' => $hardwareId,
            ]);

            return [
                'uuid' => $hardwareId,
            ];
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
        $hardwareId = $this->nativeHardwareId();

        if (is_string($hardwareId) && $hardwareId !== '') {
            return $hardwareId;
        }

        return cache()->rememberForever('autoreach.hardware_id', function (): string {
            return (string) Str::uuid();
        });
    }

    /**
     * Call a NativePHP bridge function when available.
     */
    protected function nativeBridgeCall(string $functionName, string $payload): mixed
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        return nativephp_call($functionName, $payload);
    }

    private function nativeHardwareId(): ?string
    {
        Log::debug('Bingwa native hardware id bridge probe.', [
            'nativephp_call_available' => function_exists('nativephp_call'),
        ]);

        $rawHardwareId = $this->nativeBridgeCall('Device.GetId', '{}');

        Log::debug('Bingwa native hardware id raw response.', [
            'response' => is_string($rawHardwareId) ? $rawHardwareId : null,
        ]);

        if (is_string($rawHardwareId) && $rawHardwareId !== '') {
            $decodedResponse = json_decode($rawHardwareId, true);

            if (is_array($decodedResponse)) {
                $bridgeHardwareId = $decodedResponse['data']['id'] ?? null;

                if (is_string($bridgeHardwareId) && $bridgeHardwareId !== '' && $bridgeHardwareId !== 'unknown') {
                    Log::debug('Bingwa native hardware id resolved from bridge.', [
                        'hardware_id' => $bridgeHardwareId,
                    ]);

                    return $bridgeHardwareId;
                }
            }
        }

        $hardwareId = Device::getId();

        if (is_string($hardwareId) && $hardwareId !== '' && $hardwareId !== 'unknown') {
            return $hardwareId;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeNativeBridgeDeviceInfo(mixed $rawResponse, string $hardwareId): array
    {
        if (! is_string($rawResponse) || $rawResponse === '') {
            return [];
        }

        $decodedResponse = json_decode($rawResponse, true);

        if (! is_array($decodedResponse)) {
            Log::debug('Bingwa native device info raw response was not valid JSON.', [
                'hardware_id' => $hardwareId,
            ]);

            return [];
        }

        $deviceInfoJson = $decodedResponse['data']['info'] ?? null;

        if (! is_string($deviceInfoJson) || $deviceInfoJson === '') {
            Log::debug('Bingwa native device info response did not contain info data.', [
                'hardware_id' => $hardwareId,
            ]);

            return [];
        }

        $decodedDeviceInfo = json_decode($deviceInfoJson, true);

        if (! is_array($decodedDeviceInfo)) {
            Log::debug('Bingwa native device info payload was not valid JSON.', [
                'hardware_id' => $hardwareId,
            ]);

            return [];
        }

        return array_filter([
            'name' => $decodedDeviceInfo['name'] ?? null,
            'model' => $decodedDeviceInfo['model'] ?? null,
            'manufacturer' => $decodedDeviceInfo['manufacturer'] ?? null,
            'os_name' => $decodedDeviceInfo['operatingSystem'] ?? $decodedDeviceInfo['os_name'] ?? null,
            'os_version' => $decodedDeviceInfo['osVersion'] ?? $decodedDeviceInfo['os_version'] ?? null,
            'platform' => $decodedDeviceInfo['platform'] ?? null,
            'is_virtual' => $decodedDeviceInfo['isVirtual'] ?? $decodedDeviceInfo['is_virtual'] ?? null,
            'uuid' => $hardwareId,
        ], static fn ($value): bool => $value !== null);
    }
}
