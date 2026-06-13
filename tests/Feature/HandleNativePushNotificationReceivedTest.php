<?php

require_once dirname(__DIR__, 2).'/vendor/nativephp/mobile-firebase/src/Events/PushNotificationReceived.php';

use App\Jobs\SyncBingwaTransactionsJob;
use App\Listeners\HandleNativePushNotificationReceived;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use App\Services\BingwaDeviceContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Events\PushNotification\PushNotificationReceived;

beforeEach(function (): void {
    Cache::flush();
});

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

    Bus::assertDispatched(SyncBingwaTransactionsJob::class, function (SyncBingwaTransactionsJob $job) use ($user): bool {
        return $job->userId === $user->id
            && $job->pushData['device_id'] === '45';
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

    Bus::assertNotDispatched(SyncBingwaTransactionsJob::class);
});

test('push notification received falls back to the local registration when the backend device id is stale', function () {
    Bus::fake();
    Log::spy();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'backend_device_id' => 456,
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Cache::forever(BingwaDeviceContext::HARDWARE_ID_CACHE_KEY, 'HW-12345');

    app(HandleNativePushNotificationReceived::class)->handle(new PushNotificationReceived([
        'event' => 'Native\\Mobile\\Events\\PushNotification\\PushNotificationReceived',
        'payload' => '{"transaction_id":127,"service":"data_bundles","device_id":10}',
        'transaction_id' => '127',
        'service' => 'data_bundles',
        'device_id' => '10',
    ]));

    Bus::assertDispatched(SyncBingwaTransactionsJob::class, function (SyncBingwaTransactionsJob $job) use ($user): bool {
        return $job->userId === $user->id
            && $job->pushData['transaction_id'] === '127'
            && $job->pushData['device_id'] === '10';
    });

    Log::shouldHaveReceived('warning')
        ->with(
            'Bingwa push notification backend_device_id did not match the local registration, falling back to the current device registration.',
            Mockery::on(function (array $context) use ($user): bool {
                return $context['backend_device_id'] === 10
                    && $context['local_backend_device_id'] === 456
                    && $context['user_id'] === $user->id;
            })
        )
        ->once();
});
