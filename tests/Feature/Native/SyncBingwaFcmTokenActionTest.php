<?php

use App\Actions\Autoreach\SyncBingwaFcmToken;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('fcm token sync posts the latest token to the external backend', function (): void {
    config(['services.autoreach.backend_url' => 'https://backend.example.test']);
    cache()->flush();

    $user = User::factory()->create();
    $deviceToken = (string) Str::uuid();
    $fcmToken = 'fcm-token-'.Str::uuid()->toString();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => (string) Str::uuid(),
        'device_token' => $deviceToken,
        'backend_device_id' => 456,
        'bhc_code' => 'BHC123',
    ]);

    $staleRegistration = new BingwaDeviceRegistration;
    $staleRegistration->forceFill([
        'user_id' => $user->id,
        'hardware_id' => (string) Str::uuid(),
        'device_token' => 'stale-device-token',
        'backend_device_id' => 999,
        'bhc_code' => 'STALE123',
    ]);
    $user->setRelation('bingwaDeviceRegistration', $staleRegistration);

    Http::fake([
        'https://backend.example.test/api/v1/auth/device/fcm-token' => Http::response([
            'status' => 'ok',
            'message' => 'FCM token updated.',
            'device_id' => 456,
        ]),
    ]);

    $result = app(SyncBingwaFcmToken::class)->sync($user, $fcmToken);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) use ($deviceToken, $fcmToken): bool {
        return $request->url() === 'https://backend.example.test/api/v1/auth/device/fcm-token'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.$deviceToken)
            && $request['fcm_token'] === $fcmToken;
    });
});

test('fcm token sync recovers the backend device token on unauthorized responses and retries successfully', function (): void {
    config(['services.autoreach.backend_url' => 'https://backend.example.test']);
    cache()->flush();

    $user = User::factory()->create();
    $deviceToken = 'old-device-token-401';
    $recoveredToken = 'new-device-token-401';
    $fcmToken = 'fcm-token-'.Str::uuid()->toString();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => (string) Str::uuid(),
        'device_token' => $deviceToken,
        'backend_device_id' => 456,
        'bhc_code' => 'BHC123',
    ]);

    $staleRegistration = new BingwaDeviceRegistration;
    $staleRegistration->forceFill([
        'user_id' => $user->id,
        'hardware_id' => (string) Str::uuid(),
        'device_token' => 'stale-device-token',
        'backend_device_id' => 999,
        'bhc_code' => 'STALE123',
    ]);
    $user->setRelation('bingwaDeviceRegistration', $staleRegistration);

    Http::fake(function ($request) use ($deviceToken, $recoveredToken) {
        if ($request->url() === 'https://backend.example.test/api/v1/auth/device/token/recover') {
            return Http::response([
                'status' => 'success',
                'message' => 'Device token recovered.',
                'device_token' => $recoveredToken,
                'device_id' => 456,
                'bhc_code' => 'BHC123',
            ], 200);
        }

        if ($request->url() === 'https://backend.example.test/api/v1/auth/device/fcm-token'
            && $request->hasHeader('Authorization', 'Bearer '.$deviceToken)) {
            return Http::response([
                'status' => 'failed',
                'message' => 'Unauthorized device token',
            ], 401);
        }

        if ($request->url() === 'https://backend.example.test/api/v1/auth/device/fcm-token'
            && $request->hasHeader('Authorization', 'Bearer '.$recoveredToken)) {
            return Http::response([
                'status' => 'ok',
                'message' => 'FCM token updated.',
                'device_id' => 456,
            ], 200);
        }

        return Http::response([], 404);
    });

    $result = app(SyncBingwaFcmToken::class)->sync($user, $fcmToken);

    expect($result)->toBeTrue();
    expect($user->refresh()->bingwaDeviceRegistration?->device_token)->toBe($recoveredToken);

    Http::assertSentCount(3);
});
