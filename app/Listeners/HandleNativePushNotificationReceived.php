<?php

namespace App\Listeners;

use App\Jobs\SyncBingwaTransactionsJob;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Events\PushNotification\PushNotificationReceived;

class HandleNativePushNotificationReceived
{
    public function handle(PushNotificationReceived $event): void
    {
        $data = $event->data;

        Log::debug('Bingwa push notification payload received.', [
            'data' => $data,
        ]);

        $backendDeviceId = $this->extractBackendDeviceId($data);

        if ($backendDeviceId === null) {
            Log::warning('Bingwa push notification ignored because no backend device ID was present.', [
                'data' => $data,
            ]);

            return;
        }

        $user = User::query()
            ->with('bingwaDeviceRegistration')
            ->whereHas('bingwaDeviceRegistration', function ($query) use ($backendDeviceId): void {
                $query->where('backend_device_id', $backendDeviceId);
            })
            ->first();

        if ($user === null) {
            Log::warning('Bingwa push notification ignored because no matching user/device was found.', [
                'backend_device_id' => $backendDeviceId,
                'data' => $data,
            ]);

            return;
        }

        SyncBingwaTransactionsJob::dispatch($user->getKey(), $data);

        Log::debug('Bingwa push notification dispatched the transaction sync job.', [
            'user_id' => $user->getKey(),
            'backend_device_id' => $backendDeviceId,
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
