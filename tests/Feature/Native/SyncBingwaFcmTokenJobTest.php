<?php

use App\Actions\Autoreach\RegisterBingwaDevice;
use App\Actions\Autoreach\SyncBingwaFcmToken;
use App\Jobs\SyncBingwaFcmTokenJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Str;
use Native\Mobile\Facades\PushNotifications;

test('backend fcm sync job resolves the token and hands it off to the sync action', function (): void {
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

    PushNotifications::shouldReceive('checkPermission')
        ->once()
        ->andReturn('granted');
    PushNotifications::shouldReceive('enroll')->once();
    PushNotifications::shouldReceive('getToken')
        ->once()
        ->andReturn($fcmToken);

    $syncAction = Mockery::mock(SyncBingwaFcmToken::class);
    $syncAction->shouldReceive('sync')
        ->once()
        ->with(
            Mockery::on(fn (User $argument): bool => $argument->is($user)),
            $fcmToken,
            Mockery::type('string'),
        )
        ->andReturnTrue();
    app()->instance(SyncBingwaFcmToken::class, $syncAction);

    (new SyncBingwaFcmTokenJob($user->id))->handle(app(RegisterBingwaDevice::class), app(SyncBingwaFcmToken::class));
});
