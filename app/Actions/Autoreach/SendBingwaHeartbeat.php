<?php

namespace App\Actions\Autoreach;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SendBingwaHeartbeat
{
    /**
     * Send a heartbeat to the Bingwa backend.
     */
    public function send(User $user): void
    {
        $registration = $user->bingwaDeviceRegistration;

        if ($registration === null || blank($registration->device_token)) {
            return;
        }

        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');
        $token = $registration->device_token;

        try {
            $response = $this->executeHeartbeat($baseUrl, $token);

            // If unauthorized, attempt to recover the token once and retry
            if ($response->status() === 401) {
                try {
                    $registration = app(RecoverBingwaDeviceToken::class)->recover($user);
                    $response = $this->executeHeartbeat($baseUrl, $registration->device_token);
                } catch (\Throwable $e) {
                    report(new \RuntimeException('Failed to recover token during heartbeat: '.$e->getMessage(), 0, $e));
                }
            }

            if ($response->successful()) {
                // Update the local registration model with the current timestamp
                $registration->update([
                    'last_seen_at' => now(),
                    // We don't update app_version in DB here, but we could if we added the column.
                ]);

                return;
            }

            if ($response->status() === 403 || $response->json('code') === 'device_stopped') {
                // Device has been deactivated on the backend
                $registration->update(['status' => 'stopped']);
            }

            report(new \RuntimeException("Heartbeat failed with status: {$response->status()}"));
        } catch (\Throwable $e) {
            report(new \RuntimeException('Heartbeat threw exception: '.$e->getMessage(), 0, $e));
        }
    }

    /**
     * Execute the heartbeat request.
     */
    private function executeHeartbeat(string $baseUrl, string $token): Response
    {
        return Http::retry(3, 100)
            ->timeout(30)
            ->acceptJson()
            ->withToken($token)
            ->post("{$baseUrl}/api/v1/auth/device/heartbeat", [
                'app_version' => config('app.version', '1.0.0'),
            ]);
    }
}
