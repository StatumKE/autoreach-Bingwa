<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use App\Services\BingwaDeviceContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Device;
use Tests\TestCase;

uses(TestCase::class)->use(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

test('returns the cached hardware id without calling the native bridge', function (): void {
    Cache::forever(BingwaDeviceContext::HARDWARE_ID_CACHE_KEY, 'HW-CACHED-123');

    Device::shouldReceive('getId')->never();

    expect(app(BingwaDeviceContext::class)->hardwareId())->toBe('HW-CACHED-123');
});

test('seeds the cache from the native device id', function (): void {
    Device::shouldReceive('getId')
        ->once()
        ->andReturn('HW-NATIVE-123');

    $hardwareId = app(BingwaDeviceContext::class)->hardwareId();

    expect($hardwareId)->toBe('HW-NATIVE-123');
    expect(Cache::get(BingwaDeviceContext::HARDWARE_ID_CACHE_KEY))->toBe('HW-NATIVE-123');
});

test('creates and caches a uuid when the native device id is unavailable', function (): void {
    Device::shouldReceive('getId')
        ->once()
        ->andReturn('unknown');

    $hardwareId = app(BingwaDeviceContext::class)->hardwareIdOrCreate();

    expect(Str::isUuid($hardwareId))->toBeTrue();
    expect(Cache::get(BingwaDeviceContext::HARDWARE_ID_CACHE_KEY))->toBe($hardwareId);
});

test('resolves the active registration and user from the cached hardware id', function (): void {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-REGISTERED-123',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Cache::forever(BingwaDeviceContext::HARDWARE_ID_CACHE_KEY, 'HW-REGISTERED-123');

    $context = app(BingwaDeviceContext::class);

    expect($context->registration()?->user?->is($user))->toBeTrue();
    expect($context->user()?->is($user))->toBeTrue();
});
