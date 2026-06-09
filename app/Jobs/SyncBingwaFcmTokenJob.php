<?php

namespace App\Jobs;

use App\Actions\Autoreach\RegisterBingwaDevice;
use App\Actions\Autoreach\SyncBingwaFcmToken;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Native\Mobile\Facades\PushNotifications;

class SyncBingwaFcmTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $userId,
        public ?string $flowId = null,
    ) {}

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public int $tries = 0;

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

        if (function_exists('nativephp_can') && ! nativephp_can('PushNotification.GetToken')) {
            Log::error('Bingwa FCM sync job aborted because PushNotification.GetToken is not registered in the native bridge.', [
                'component' => 'fcm_sync',
                'flow_id' => $flowId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $user = User::query()
            ->with('bingwaDeviceRegistration')
            ->find($this->userId);

        if (! $user instanceof User) {
            Log::warning('Bingwa FCM sync job skipped because the user could not be found.', [
                'component' => 'fcm_sync',
                'flow_id' => $flowId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        Log::debug('Bingwa FCM sync job started.', [
            'component' => 'fcm_sync',
            'user_id' => $user->getKey(),
            'flow_id' => $flowId,
            'attempt' => $this->attempts(),
            'tries' => $this->tries,
        ]);

        try {
            if (! $this->ensureBingwaDeviceRegistered($user, $registerBingwaDevice)) {
                $attempt = $this->attempts();
                $delay = $this->backoff()[$attempt - 1] ?? 60;

                Log::debug('Bingwa device registration not yet available in backend job; requeueing.', [
                    'component' => 'fcm_sync',
                    'user_id' => $user->getKey(),
                    'flow_id' => $flowId,
                    'attempt' => $attempt,
                    'tries' => $this->tries,
                    'delay_seconds' => $delay,
                ]);

                $this->release($delay);

                return;
            }
        } catch (ValidationException $validationException) {
            Log::error('Bingwa device registration failed permanently due to validation mismatch (email and connect ID).', [
                'component' => 'fcm_sync',
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
                'errors' => $validationException->errors(),
            ]);

            $this->fail($validationException);

            return;
        }

        if (! $this->enrollmentRequested) {
            try {
                $permissionStatus = PushNotifications::checkPermission();

                Log::debug('Bingwa FCM permission status checked in backend job.', [
                    'component' => 'fcm_sync',
                    'user_id' => $user->getKey(),
                    'flow_id' => $flowId,
                    'permission_status' => $permissionStatus,
                ]);

                if ($permissionStatus === 'denied') {
                    Log::warning('Bingwa FCM enrollment skipped because permission is denied.', [
                        'component' => 'fcm_sync',
                        'user_id' => $user->getKey(),
                        'flow_id' => $flowId,
                    ]);

                    return;
                }

                PushNotifications::enroll();
                $this->enrollmentRequested = true;

                Log::debug('Bingwa FCM enrollment requested from backend job.', [
                    'component' => 'fcm_sync',
                    'user_id' => $user->getKey(),
                    'flow_id' => $flowId,
                ]);
            } catch (\Throwable $throwable) {
                Log::warning('Bingwa FCM enrollment failed in backend job.', [
                    'component' => 'fcm_sync',
                    'user_id' => $user->getKey(),
                    'flow_id' => $flowId,
                    'message' => $throwable->getMessage(),
                ]);

                report($throwable);

                $this->release($this->backoff()[0]);

                return;
            }
        }

        $token = $this->resolveToken();

        if (blank($token)) {
            $attempt = $this->attempts();
            $delay = $this->backoff()[$attempt - 1] ?? 60;

            Log::debug('Bingwa FCM token not yet available in backend job; requeueing.', [
                'component' => 'fcm_sync',
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
                'attempt' => $attempt,
                'tries' => $this->tries,
                'delay_seconds' => $delay,
            ]);

            $this->release($delay);

            return;
        }

        if ($syncBingwaFcmToken->sync($user, $token, $flowId)) {
            Log::debug('Bingwa FCM sync job completed successfully.', [
                'component' => 'fcm_sync',
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
            ]);

            return;
        }

        $attempt = $this->attempts();
        $delay = $this->backoff()[$attempt - 1] ?? 60;

        Log::debug('Bingwa FCM backend sync did not complete; requeueing job.', [
            'component' => 'fcm_sync',
            'user_id' => $user->getKey(),
            'flow_id' => $flowId,
            'attempt' => $attempt,
            'tries' => $this->tries,
            'delay_seconds' => $delay,
        ]);

        $this->release($delay);

        Log::debug('Bingwa FCM sync job finished.', [
            'component' => 'fcm_sync',
            'user_id' => $user->getKey(),
            'flow_id' => $flowId,
        ]);
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
                'component' => 'fcm_sync',
                'user_id' => $user->getKey(),
                'flow_id' => $this->flowId,
                'backend_device_id' => $registration->backend_device_id,
            ]);

            return true;
        } catch (ValidationException $validationException) {
            throw $validationException;
        } catch (\Throwable $throwable) {
            Log::warning('Bingwa device registration failed in backend job.', [
                'component' => 'fcm_sync',
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
                    'component' => 'fcm_sync',
                    'user_id' => $this->userId,
                    'flow_id' => $this->flowId,
                    'token_hash' => hash('sha256', $token),
                ]);
            }

            return is_string($token) && $token !== '' ? $token : null;
        } catch (\Throwable $throwable) {
            Log::warning('Bingwa FCM token lookup failed in backend job.', [
                'component' => 'fcm_sync',
                'user_id' => $this->userId,
                'flow_id' => $this->flowId,
                'message' => $throwable->getMessage(),
            ]);

            report($throwable);

            return null;
        }
    }
}
