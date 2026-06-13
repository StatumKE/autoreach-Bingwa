<?php

namespace App\Listeners;

use App\Jobs\SyncBingwaTransactionsJob;
use App\Models\BingwaDeviceRegistration;
use App\Services\BingwaDeviceContext;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Events\PushNotification\PushNotificationReceived;

class HandleNativePushNotificationReceived
{
    public function handle(PushNotificationReceived $event): void
    {
        $data = $event->data;

        Log::debug('Bingwa push notification payload received.', [
            'data' => $data,
            'transaction_id' => $data['transaction_id'] ?? null,
            'service' => $data['service'] ?? null,
            'device_id' => $data['device_id'] ?? null,
        ]);

        $backendDeviceId = $this->extractBackendDeviceId($data);

        if ($backendDeviceId === null) {
            Log::warning('Bingwa push notification ignored because no backend device ID was present.', [
                'data' => $data,
            ]);

            return;
        }

        $registration = BingwaDeviceRegistration::query()
            ->with('user')
            ->where('backend_device_id', $backendDeviceId)
            ->first();

        if ($registration === null) {
            $registration = app(BingwaDeviceContext::class)->registration();
        }

        $user = $registration?->user;

        if ($user === null) {
            Log::warning('Bingwa push notification ignored because no matching user/device was found.', [
                'backend_device_id' => $backendDeviceId,
                'local_backend_device_id' => $registration?->backend_device_id,
                'data' => $data,
            ]);

            return;
        }

        if ($registration->backend_device_id !== $backendDeviceId) {
            Log::warning('Bingwa push notification backend_device_id did not match the local registration, falling back to the current device registration.', [
                'backend_device_id' => $backendDeviceId,
                'local_backend_device_id' => $registration->backend_device_id,
                'user_id' => $user->getKey(),
                'data' => $data,
            ]);
        }

        SyncBingwaTransactionsJob::dispatch($user->getKey(), $data);

        if (function_exists('nativephp_call')) {
            try {
                nativephp_call('WakeQueueWorker', '{}');
            } catch (\Throwable $e) {
                // Ignore if bridge is unavailable
            }
        }

        Log::debug('Bingwa push notification dispatched the transaction sync job.', [
            'user_id' => $user->getKey(),
            'backend_device_id' => $backendDeviceId,
            'transaction_id' => $data['transaction_id'] ?? null,
            'service' => $data['service'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractBackendDeviceId(array $data): ?int
    {
        $value = $data['device_id'] ?? null;

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($data['payload'] ?? null)) {
            $decodedPayload = json_decode($data['payload'], true);

            if (is_array($decodedPayload)) {
                $payloadDeviceId = $decodedPayload['device_id'] ?? null;

                if (is_string($payloadDeviceId) && is_numeric($payloadDeviceId)) {
                    return (int) $payloadDeviceId;
                }

                if (is_int($payloadDeviceId)) {
                    return $payloadDeviceId;
                }
            }
        }

        return null;
    }
}
