<?php

namespace App\Actions\Autoreach;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendBingwaHeartbeat
{
    /**
     * Send a heartbeat to the Bingwa backend.
     */
    public function send(User $user): bool
    {
        $user = $user->fresh(['bingwaDeviceRegistration']) ?? $user;
        $registration = $user->bingwaDeviceRegistration;

        if ($registration === null || blank($registration->device_token)) {
            Log::warning('Bingwa heartbeat skipped because the user has no device token.', [
                'component' => 'heartbeat',
                'user_id' => $user->getKey(),
                'registration_id' => $registration?->getKey(),
            ]);

            return false;
        }

        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');
        $token = $registration->device_token;

        Log::info('Bingwa heartbeat request starting.', [
            'component' => 'heartbeat',
            'user_id' => $user->getKey(),
            'registration_id' => $registration->getKey(),
            'backend_device_id' => $registration->backend_device_id,
            'base_url' => $baseUrl,
        ]);

        try {
            $response = $this->executeHeartbeat($baseUrl, $token);

            // If unauthorized, attempt to recover the token once and retry
            if ($response->status() === 401) {
                Log::warning('Bingwa heartbeat returned unauthorized; attempting token recovery.', [
                    'component' => 'heartbeat',
                    'user_id' => $user->getKey(),
                    'registration_id' => $registration->getKey(),
                    'backend_device_id' => $registration->backend_device_id,
                    'status' => $response->status(),
                ]);

                try {
                    $registration = app(RecoverBingwaDeviceToken::class)->recover($user);
                    $response = $this->executeHeartbeat($baseUrl, $registration->device_token);
                } catch (\Throwable $e) {
                    report(new \RuntimeException('Failed to recover token during heartbeat: '.$e->getMessage(), 0, $e));
                    Log::error('Bingwa heartbeat token recovery failed.', [
                        'component' => 'heartbeat',
                        'user_id' => $user->getKey(),
                        'registration_id' => $registration->getKey(),
                        'backend_device_id' => $registration->backend_device_id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            if ($response->successful()) {
                // Update the local registration model with the current timestamp
                $registration->update([
                    'last_seen_at' => now(),
                    // We don't update app_version in DB here, but we could if we added the column.
                ]);

                Log::info('Bingwa heartbeat accepted by backend.', [
                    'component' => 'heartbeat',
                    'user_id' => $user->getKey(),
                    'registration_id' => $registration->getKey(),
                    'backend_device_id' => $registration->backend_device_id,
                    'status' => $response->status(),
                    'response_body' => $this->responseBody($response),
                    'recorded_at' => now()->toISOString(),
                ]);

                return true;
            }

            if ($response->status() === 403 || $response->json('code') === 'device_stopped') {
                // Device has been deactivated on the backend
                $registration->update(['status' => 'stopped']);
            }

            report(new \RuntimeException("Heartbeat failed with status: {$response->status()}"));
            Log::warning('Bingwa heartbeat was rejected by backend.', [
                'component' => 'heartbeat',
                'user_id' => $user->getKey(),
                'registration_id' => $registration->getKey(),
                'backend_device_id' => $registration->backend_device_id,
                'status' => $response->status(),
                'response_code' => $response->json('code'),
                'response_message' => $response->json('message'),
                'response_body' => $this->responseBody($response),
            ]);

            return false;
        } catch (\Throwable $e) {
            report(new \RuntimeException('Heartbeat threw exception: '.$e->getMessage(), 0, $e));
            Log::error('Bingwa heartbeat threw an exception.', [
                'component' => 'heartbeat',
                'user_id' => $user->getKey(),
                'registration_id' => $registration->getKey(),
                'backend_device_id' => $registration->backend_device_id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Execute the heartbeat request.
     */
    private function executeHeartbeat(string $baseUrl, string $token): Response
    {
        return Http::retry(3, 100, function (\Throwable $exception): bool {
            return $exception instanceof ConnectionException;
        }, throw: false)
            ->timeout(30)
            ->acceptJson()
            ->withToken($token)
            ->post("{$baseUrl}/api/v1/auth/device/heartbeat", [
                'app_version' => config('app.version', '1.0.0'),
            ]);
    }

    private function responseBody(Response $response): string
    {
        return trim($response->body());
    }
}
