<?php

require_once dirname(__DIR__, 2).'/vendor/nativephp/mobile-firebase/src/Events/PushNotificationReceived.php';

use App\Jobs\SyncBingwaTransactionsJob;
use App\Listeners\HandleNativePushNotificationReceived;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Native\Mobile\Events\PushNotification\PushNotificationReceived;

test('push notification received dispatches a transaction sync job for the matching device', function () {
    Bus::fake();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'backend_device_id' => 45,
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    app(HandleNativePushNotificationReceived::class)->handle(new PushNotificationReceived([
        'event' => 'Native\\Mobile\\Events\\PushNotification\\PushNotificationReceived',
        'payload' => '{"transaction_id":123,"service":"sms","device_id":45}',
        'transaction_id' => '123',
        'service' => 'sms',
        'device_id' => '45',
    ]));

    Bus::assertDispatchedSync(SyncBingwaTransactionsJob::class, function (SyncBingwaTransactionsJob $job): bool {
        return $job->pushData['device_id'] === '45';
    });
});

test('push notification received ignores payloads without a backend device id', function () {
    Bus::fake();

    app(HandleNativePushNotificationReceived::class)->handle(new PushNotificationReceived([
        'event' => 'Native\\Mobile\\Events\\PushNotification\\PushNotificationReceived',
        'payload' => '{"transaction_id":123,"service":"sms"}',
        'transaction_id' => '123',
        'service' => 'sms',
    ]));

    Bus::assertNotDispatchedSync(SyncBingwaTransactionsJob::class);
});
