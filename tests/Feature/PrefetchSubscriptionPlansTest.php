<?php

use App\Actions\Autoreach\SendBingwaHeartbeat;
use App\Jobs\PrefetchSubscriptionPlansJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use App\Services\AndroidRuntimeScheduler;
use App\Services\BingwaDeviceContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Native\Mobile\Facades\Device;

test('dashboard page dispatches prefetch subscription plans job', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('dashboard');

    Queue::assertPushed(PrefetchSubscriptionPlansJob::class, function ($job) use ($user): bool {
        return $job->userId === $user->id;
    });
});

test('prefetch job caches plans successfully', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token-123',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Http::fake([
        'backend.statum.co.ke/api/v1/subscription/plans/hybrid' => Http::response([
            'status' => 'ok',
            'plans' => [
                [
                    'id' => 10,
                    'code' => 'usage_100',
                    'name' => '100 requests',
                    'type' => 'usage_pack',
                    'price' => 100,
                ],
            ],
            'sambaza_line' => '2547XXXXXXXX',
            'sambaza_ussd_prompts' => [],
        ], 200),
    ]);

    // Ensure cache is empty
    $cacheKey = sprintf('autoreach.subscription_plans.%d.%s', $user->id, sha1('raw-device-token-123'));
    Cache::forget($cacheKey);

    PrefetchSubscriptionPlansJob::dispatchSync($user->id);

    $cached = Cache::get($cacheKey);
    expect($cached)->not->toBeNull();
    expect($cached['plans'][0]['name'])->toBe('100 requests');
});

test('android runtime scheduler triggers prefetch when due', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token-123',
        'bhc_code' => 'BHC-ZXCVB',
        'last_seen_at' => now(),
    ]);

    Http::fake([
        'backend.statum.co.ke/api/v1/subscription/plans/hybrid' => Http::response([
            'status' => 'ok',
            'plans' => [
                [
                    'id' => 10,
                    'code' => 'usage_100',
                    'name' => '100 requests',
                    'type' => 'usage_pack',
                    'price' => 100,
                ],
            ],
            'sambaza_line' => '2547XXXXXXXX',
            'sambaza_ussd_prompts' => [],
        ], 200),
    ]);

    $prefetchKey = AndroidRuntimeScheduler::plansPrefetchKey($user->id);
    Cache::forget($prefetchKey);

    // Mock device context to return our test user
    $deviceContext = mock(BingwaDeviceContext::class);
    $deviceContext->shouldReceive('user')->andReturn($user);

    $scheduler = new AndroidRuntimeScheduler(
        app(SendBingwaHeartbeat::class),
        $deviceContext
    );

    // Run scheduler
    $result = $scheduler->runDueTasks(now());

    expect($result['ran'])->toBeTrue();
    expect(Cache::has($prefetchKey))->toBeTrue();
});

test('prefetch job handles invalid user id gracefully', function () {
    PrefetchSubscriptionPlansJob::dispatchSync(99999);
})->throwsNoExceptions();

test('prefetch job handles registration validation failures gracefully', function () {
    $user = User::factory()->create();
    PrefetchSubscriptionPlansJob::dispatchSync($user->id);
})->throwsNoExceptions();

test('android runtime scheduler skips prefetch if recently run', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token-123',
        'bhc_code' => 'BHC-ZXCVB',
        'last_seen_at' => now(),
    ]);

    $requestCount = 0;
    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        return Http::response([], 200);
    });

    $prefetchKey = AndroidRuntimeScheduler::plansPrefetchKey($user->id);
    Cache::put($prefetchKey, now()->toIso8601String(), 3600);

    $deviceContext = mock(BingwaDeviceContext::class);
    $deviceContext->shouldReceive('user')->andReturn($user);

    $scheduler = new AndroidRuntimeScheduler(
        app(SendBingwaHeartbeat::class),
        $deviceContext
    );

    // Run scheduler
    $result = $scheduler->runDueTasks(now());

    expect($result['ran'])->toBeTrue();
    expect($requestCount)->toBe(0);
});
