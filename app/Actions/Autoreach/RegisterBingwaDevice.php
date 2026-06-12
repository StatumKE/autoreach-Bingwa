<?php

namespace App\Actions\Autoreach;

use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use App\Services\BingwaDeviceContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Native\Mobile\Facades\Device;

class RegisterBingwaDevice
{
    public function __construct(
        private BingwaDeviceContext $deviceContext,
    ) {}

    /**
     * Register the current device with the Autoreach backend.
     */
    public function registerOnBackend(User $user): array
    {
        $hardwareId = $this->deviceContext->hardwareIdOrCreate();

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
            'component' => 'device_registration',
            'url' => rtrim((string) config('services.autoreach.backend_url'), '/').'/api/v1/auth/device/register/hybrid',
            'payload' => $payload,
        ]);

        $response = Http::baseUrl(rtrim((string) config('services.autoreach.backend_url'), '/'))
            ->retry(3, 100, function (\Throwable $exception): bool {
                return $exception instanceof ConnectionException;
            }, throw: false)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post('/api/v1/auth/device/register/hybrid', $payload);

        Log::debug('Bingwa device registration response received.', [
            'component' => 'device_registration',
            'status' => $response->status(),
        ]);

        if ($response->successful()) {
            return array_merge(
                $response->json() ?? [],
                ['hardware_id' => $hardwareId],
            );
        }

        if ($response->status() === 422) {
            $this->throwValidationException($response);
        }

        $response->throw();
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceInfo(string $hardwareId): array
    {
        Log::debug('Bingwa native device info bridge probe.', [
            'component' => 'device_registration',
            'hardware_id' => $hardwareId,
            'nativephp_call_available' => function_exists('nativephp_call'),
        ]);

        $rawDeviceInfo = $this->nativeBridgeCall('Device.GetInfo', '{}');

        Log::debug('Bingwa native device info raw response.', [
            'component' => 'device_registration',
            'hardware_id' => $hardwareId,
            'response' => is_string($rawDeviceInfo) ? $rawDeviceInfo : null,
        ]);

        $deviceInfo = $this->decodeNativeBridgeDeviceInfo($rawDeviceInfo, $hardwareId);

        if ($deviceInfo === []) {
            $rawNativeInfo = Device::getInfo();
            if (is_string($rawNativeInfo) && $rawNativeInfo !== '') {
                $decoded = json_decode($rawNativeInfo, true);
                if (is_array($decoded)) {
                    $deviceInfo = array_filter([
                        'name' => $decoded['name'] ?? null,
                        'model' => $decoded['model'] ?? null,
                        'manufacturer' => $decoded['manufacturer'] ?? null,
                        'os_name' => $decoded['operatingSystem'] ?? $decoded['os_name'] ?? null,
                        'os_version' => $decoded['osVersion'] ?? $decoded['os_version'] ?? null,
                        'platform' => $decoded['platform'] ?? null,
                        'is_virtual' => $decoded['isVirtual'] ?? $decoded['is_virtual'] ?? null,
                        'uuid' => $hardwareId,
                    ], static fn ($value): bool => $value !== null && $value !== '');
                }
            }
        }

        $merged = array_merge([
            'model' => 'Generic',
            'manufacturer' => 'Unknown',
            'os_name' => 'Android',
            'os_version' => '14',
            'platform' => 'android',
            'is_virtual' => false,
            'uuid' => $hardwareId,
        ], $deviceInfo);

        if (! isset($merged['name'])) {
            $name = trim(($merged['manufacturer'] !== 'Unknown' ? $merged['manufacturer'] : '').' '.($merged['model'] !== 'Generic' ? $merged['model'] : ''));
            $merged['name'] = $name !== '' ? $name : 'Generic Android Device';
        }

        return $merged;
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
        $hardwareId = $backendRegistration['hardware_id'] ?? $this->deviceContext->hardwareIdOrCreate();

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

        app(FetchBingwaSubscriptionPlans::class)->forget($user);

        return $record;
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
                'component' => 'device_registration',
                'hardware_id' => $hardwareId,
            ]);

            return [];
        }

        $deviceInfoJson = $decodedResponse['data']['info'] ?? null;

        if (! is_string($deviceInfoJson) || $deviceInfoJson === '') {
            Log::debug('Bingwa native device info response did not contain info data.', [
                'component' => 'device_registration',
                'hardware_id' => $hardwareId,
            ]);

            return [];
        }

        $decodedDeviceInfo = json_decode($deviceInfoJson, true);

        if (! is_array($decodedDeviceInfo)) {
            Log::debug('Bingwa native device info payload was not valid JSON.', [
                'component' => 'device_registration',
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
        ], static fn ($value): bool => $value !== null && $value !== '');
    }
}
