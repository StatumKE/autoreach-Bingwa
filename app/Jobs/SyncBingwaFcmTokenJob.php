<?php

namespace App\Jobs;

use App\Actions\Autoreach\RegisterBingwaDevice;
use App\Actions\Autoreach\SyncBingwaFcmToken;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Native\Mobile\Facades\PushNotifications;

class SyncBingwaFcmTokenJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ?string $flowId = null,
    ) {}

    public int $tries = 6;

    public bool $enrollmentRequested = false;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 10, 15, 30, 45];
    }

    public function handle(
        RegisterBingwaDevice $registerBingwaDevice,
        SyncBingwaFcmToken $syncBingwaFcmToken,
    ): void {
        $flowId = $this->flowId ??= (string) Str::uuid();

        $user = User::query()->with('bingwaDeviceRegistration')->first();

        if (! $user instanceof User) {
            Log::warning('Bingwa FCM sync job skipped because the user could not be found.', [
                'flow_id' => $flowId,
            ]);

            return;
        }

        Log::debug('Bingwa FCM sync job started.', [
            'user_id' => $user->getKey(),
            'flow_id' => $flowId,
            'attempt' => $this->attempts(),
            'tries' => $this->tries,
        ]);

        if (! $this->ensureBingwaDeviceRegistered($user, $registerBingwaDevice)) {
            $attempt = $this->attempts();
            $delay = $this->backoff()[$attempt - 1] ?? 60;

            Log::debug('Bingwa device registration not yet available in backend job; requeueing.', [
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
                'attempt' => $attempt,
                'tries' => $this->tries,
                'delay_seconds' => $delay,
            ]);

            if ($attempt < $this->tries) {
                $this->release($delay);
            }

            return;
        }

        if (! $this->enrollmentRequested) {
            try {
                $permissionStatus = PushNotifications::checkPermission();

                Log::debug('Bingwa FCM permission status checked in backend job.', [
                    'user_id' => $user->getKey(),
                    'flow_id' => $flowId,
                    'permission_status' => $permissionStatus,
                ]);

                if ($permissionStatus === 'denied') {
                    Log::warning('Bingwa FCM enrollment skipped because permission is denied.', [
                        'user_id' => $user->getKey(),
                        'flow_id' => $flowId,
                    ]);

                    return;
                }

                PushNotifications::enroll();
                $this->enrollmentRequested = true;

                Log::debug('Bingwa FCM enrollment requested from backend job.', [
                    'user_id' => $user->getKey(),
                    'flow_id' => $flowId,
                ]);
            } catch (\Throwable $throwable) {
                Log::warning('Bingwa FCM enrollment failed in backend job.', [
                    'user_id' => $user->getKey(),
                    'flow_id' => $flowId,
                    'message' => $throwable->getMessage(),
                ]);

                report($throwable);

                if ($this->attempts() < $this->tries) {
                    $this->release($this->backoff()[0]);
                }

                return;
            }
        }

        $token = $this->resolveToken();

        if (blank($token)) {
            $attempt = $this->attempts();
            $delay = $this->backoff()[$attempt - 1] ?? 60;

            Log::debug('Bingwa FCM token not yet available in backend job; requeueing.', [
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
                'attempt' => $attempt,
                'tries' => $this->tries,
                'delay_seconds' => $delay,
            ]);

            if ($attempt < $this->tries) {
                $this->release($delay);
            }

            return;
        }

        if ($syncBingwaFcmToken->sync($user, $token, $flowId)) {
            Log::debug('Bingwa FCM sync job completed successfully.', [
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
            ]);

            return;
        }

        $attempt = $this->attempts();
        $delay = $this->backoff()[$attempt - 1] ?? 60;

        Log::debug('Bingwa FCM backend sync did not complete; requeueing job.', [
            'user_id' => $user->getKey(),
            'flow_id' => $flowId,
            'attempt' => $attempt,
            'tries' => $this->tries,
            'delay_seconds' => $delay,
        ]);

        if ($attempt < $this->tries) {
            $this->release($delay);
        }
    }

    private function ensureBingwaDeviceRegistered(
        User $user,
        RegisterBingwaDevice $registerBingwaDevice,
    ): bool {
        $registration = $user->bingwaDeviceRegistration;

        if ($registration instanceof BingwaDeviceRegistration && filled($registration->device_token)) {
            return true;
        }

        try {
            $backendRegistration = $registerBingwaDevice->registerOnBackend($user);
            $registration = $registerBingwaDevice->persistRegistration($user, $backendRegistration);

            $user->setRelation('bingwaDeviceRegistration', $registration);

            Log::debug('Bingwa device registration completed in backend job.', [
                'user_id' => $user->getKey(),
                'flow_id' => $this->flowId,
                'backend_device_id' => $registration->backend_device_id,
            ]);

            return true;
        } catch (\Throwable $throwable) {
            Log::warning('Bingwa device registration failed in backend job.', [
                'user_id' => $user->getKey(),
                'flow_id' => $this->flowId,
                'message' => $throwable->getMessage(),
            ]);

            report($throwable);

            return false;
        }
    }

    private function resolveToken(): ?string
    {
        try {
            $token = PushNotifications::getToken();

            if (is_string($token) && $token !== '') {
                Log::debug('Bingwa FCM token resolved in backend job.', [
                    'user_id' => $this->userId(),
                    'flow_id' => $this->flowId,
                    'token_hash' => hash('sha256', $token),
                ]);
            }

            return is_string($token) && $token !== '' ? $token : null;
        } catch (\Throwable $throwable) {
            Log::warning('Bingwa FCM token lookup failed in backend job.', [
                'user_id' => $this->userId(),
                'flow_id' => $this->flowId,
                'message' => $throwable->getMessage(),
            ]);

            report($throwable);

            return null;
        }
    }

    private function userId(): int
    {
        $user = User::query()->first();

        return $user ? $user->id : 0;
    }
}
