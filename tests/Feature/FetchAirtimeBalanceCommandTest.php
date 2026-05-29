<?php

use App\Jobs\RefreshAirtimeBalanceJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

test('it dispatches refresh airtime balance job for active registered users', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
        'status' => 'active',
    ]);

    $exitCode = Artisan::call('bingwa:fetch-airtime-balance');

    expect($exitCode)->toBe(0);
    Queue::assertPushed(RefreshAirtimeBalanceJob::class);
});

test('it does not dispatch job when there are no active registered users', function (): void {
    Queue::fake();

    $exitCode = Artisan::call('bingwa:fetch-airtime-balance');

    expect($exitCode)->toBe(0);
    Queue::assertNotPushed(RefreshAirtimeBalanceJob::class);
});

test('it does not dispatch job when user registration status is stopped', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
        'status' => 'stopped',
    ]);

    $exitCode = Artisan::call('bingwa:fetch-airtime-balance');

    expect($exitCode)->toBe(0);
    Queue::assertNotPushed(RefreshAirtimeBalanceJob::class);
});
