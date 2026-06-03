<?php

use App\Jobs\SyncBingwaTransactionsJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Native\Mobile\Facades\Device;

beforeEach(function (): void {
    Cache::flush();
    Device::shouldReceive('getId')->andReturn('HW-12345');

    $this->user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $this->user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);
});

test('bingwa sync transactions command queues background job', function (): void {
    Queue::fake();

    $exitCode = Artisan::call('bingwa:sync-transactions');

    expect($exitCode)->toBe(0);

    Queue::assertPushed(SyncBingwaTransactionsJob::class, function (SyncBingwaTransactionsJob $job): bool {
        return $job->userId === $this->user->id;
    });
});
