<?php

use App\Jobs\SendHeartbeatJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $this->user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);
});

test('bingwa heartbeat command queues background job', function (): void {
    Queue::fake();
    Log::spy();

    $exitCode = Artisan::call('bingwa:heartbeat');

    expect($exitCode)->toBe(0);

    Queue::assertPushed(SendHeartbeatJob::class);

    Log::shouldHaveReceived('info')
        ->with('Bingwa heartbeat job queued.')
        ->once();
});
