<?php

namespace App\Actions\Autoreach;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncBingwaFcmToken
{
    /**
     * Sync the latest FCM token to the Autoreach backend.
     */
    public function sync(User $user, string $fcmToken, ?string $flowId = null): bool
    {
        $registration = $user->bingwaDeviceRegistration;

        if ($registration === null || blank($registration->device_token)) {
            Log::debug('Bingwa FCM sync skipped because the device is not registered.', [
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
            ]);

            return false;
        }

        if (blank($fcmToken)) {
            Log::warning('Bingwa FCM sync skipped because the token was empty.', [
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
            ]);

            return false;
        }

        if ($this->isAlreadySynced($registration->device_token, $fcmToken)) {
            Log::debug('Bingwa FCM token sync skipped because this token pair was already processed.', [
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
                'device_token_hash' => $this->hashValue($registration->device_token),
                'fcm_token_hash' => $this->hashValue($fcmToken),
            ]);

            return true;
        }

        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');
        $payload = ['fcm_token' => $fcmToken];
        $response = null;

        Log::debug('Bingwa FCM sync request started.', [
            'user_id' => $user->getKey(),
            'flow_id' => $flowId,
            'device_token_hash' => $this->hashValue($registration->device_token),
            'fcm_token_hash' => $this->hashValue($fcmToken),
            'url' => "{$baseUrl}/api/v1/auth/device/fcm-token",
        ]);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::baseUrl($baseUrl)
                    ->acceptJson()
                    ->asJson()
                    ->timeout(30)
                    ->withToken($registration->device_token)
                    ->post('/api/v1/auth/device/fcm-token', $payload);

                Log::debug('Bingwa FCM sync attempt completed.', [
                    'user_id' => $user->getKey(),
                    'flow_id' => $flowId,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                ]);

                if ($response->successful()) {
                    $this->markSynced($registration->device_token, $fcmToken);

                    Log::debug('Bingwa FCM backend response received.', [
                        'user_id' => $user->getKey(),
                        'flow_id' => $flowId,
                        'status' => $response->status(),
                        'body' => $response->json(),
                    ]);

                    Log::debug('Bingwa FCM token sync completed successfully.', [
                        'user_id' => $user->getKey(),
                        'flow_id' => $flowId,
                        'device_id' => $response->json('device_id'),
                    ]);

                    Log::debug('Bingwa FCM backend POST confirmed.', [
                        'user_id' => $user->getKey(),
                        'flow_id' => $flowId,
                        'device_id' => $response->json('device_id'),
                    ]);

                    return true;
                }
            } catch (Throwable $throwable) {
                Log::warning('Bingwa FCM sync attempt threw an exception.', [
                    'user_id' => $user->getKey(),
                    'flow_id' => $flowId,
                    'attempt' => $attempt,
                    'message' => $throwable->getMessage(),
                ]);

                report($throwable);
            }
        }

        $this->logFailure($user, $registration->device_token, $fcmToken, $response, $flowId);

        return false;
    }

    private function isAlreadySynced(string $deviceToken, string $fcmToken): bool
    {
        return cache()->has($this->cacheKey($deviceToken, $fcmToken));
    }

    private function markSynced(string $deviceToken, string $fcmToken): void
    {
        cache()->forever($this->cacheKey($deviceToken, $fcmToken), true);
    }

    private function cacheKey(string $deviceToken, string $fcmToken): string
    {
        return 'autoreach.bingwa.fcm.sync.'.hash('sha256', $deviceToken).'.'.hash('sha256', $fcmToken);
    }

    private function hashValue(string $value): string
    {
        return Str::substr(hash('sha256', $value), 0, 16);
    }

    private function logFailure(
        User $user,
        string $deviceToken,
        string $fcmToken,
        ?Response $response,
        ?string $flowId,
    ): void {
        Log::warning('Bingwa FCM token sync failed.', [
            'user_id' => $user->getKey(),
            'flow_id' => $flowId,
            'device_token_hash' => $this->hashValue($deviceToken),
            'fcm_token_hash' => $this->hashValue($fcmToken),
            'status' => $response?->status(),
            'response_body' => $response?->json(),
        ]);
    }
}
