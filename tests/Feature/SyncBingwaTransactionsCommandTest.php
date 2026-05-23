<?php

use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $this->user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);
});

test('bingwa sync transactions command logs backend fetch lifecycle', function (): void {
    Log::spy();

    Http::fake([
        'backend.statum.co.ke/api/v1/jobs/next/data_bundles*' => Http::response('', 204),
        'backend.statum.co.ke/api/v1/jobs/next/sms*' => Http::response('', 204),
        'backend.statum.co.ke/api/v1/jobs/next/airtime*' => Http::response('', 204),
    ]);

    $exitCode = Artisan::call('bingwa:sync-transactions', [
        '--user-id' => $this->user->id,
    ]);

    expect($exitCode)->toBe(0);

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa transactions sync fetching backend jobs.',
            Mockery::on(fn (array $context): bool => $context['user_id'] === $this->user->id)
        )
        ->once();

    Log::shouldHaveReceived('debug')
        ->with(
            'Bingwa transactions sync backend fetch finished.',
            Mockery::on(fn (array $context): bool => $context['user_id'] === $this->user->id && $context['synced'] === 0 && $context['failed'] === 0)
        )
        ->once();
});
