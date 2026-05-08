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

    $registration = new BingwaDeviceRegistration;
    $registration->forceFill([
        'user_id' => $user->id,
        'hardware_id' => (string) Str::uuid(),
        'device_token' => $deviceToken,
        'backend_device_id' => 456,
        'bhc_code' => 'BHC123',
    ]);

    $user->setRelation('bingwaDeviceRegistration', $registration);

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
