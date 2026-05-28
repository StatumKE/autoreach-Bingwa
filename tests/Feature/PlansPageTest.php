<?php

use App\Jobs\SyncRemoteSubscriptionPurchaseJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Native\Mobile\Facades\Device;

test('plans page can be rendered on mobile', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user);
    Device::shouldReceive('getId')->andReturn('HW-12345');

    $this->get(route('plans'))
        ->assertOk()
        ->assertSee('Subscriptions');
});

test('plans load from the backend using the saved device token', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user);

    Http::fake([
        'backend.statum.co.ke/api/v1/subscription/plans/hybrid' => Http::response([
            'status' => 'ok',
            'data' => [
                [
                    'id' => 5,
                    'code' => '1_week',
                    'name' => '1 week',
                    'tagline' => null,
                    'description' => null,
                    'type' => 'time_unlimited',
                    'app_scope' => 'hybrid',
                    'price' => 400,
                    'duration_days' => 7,
                    'ussd_requests_included' => null,
                    'is_active' => true,
                ],
            ],
            'plans' => [
                [
                    'id' => 5,
                    'code' => '1_week',
                    'name' => '1 week',
                    'tagline' => null,
                    'description' => null,
                    'type' => 'time_unlimited',
                    'app_scope' => 'hybrid',
                    'price' => 400,
                    'duration_days' => 7,
                    'ussd_requests_included' => null,
                    'is_active' => true,
                ],
            ],
            'sambaza_line' => '2547XXXXXXXX',
            'sambaza_ussd_prompts' => ['prompt one', 'prompt two'],
        ], 200),
    ]);

    $response = Livewire::test('plans')
        ->call('loadPlans');

    $response->assertHasNoErrors();
    $response->assertSet('plans.0.name', '1 week');
    $response->assertSee('KES 400');
    $response->assertSee('1 week');
    $response->assertSee('plans-reveal');
    $response->assertSee('Duration');
    $response->assertSee('7 days');
    $response->assertDontSee('USSD requests');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://backend.statum.co.ke/api/v1/subscription/plans/hybrid'
            && $request->hasHeader('Authorization', 'Bearer raw-device-token');
    });
});

test('plans page renders cached backend plans immediately without a network fetch', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $cachedPlans = [
        'plans' => [
            [
                'id' => 5,
                'code' => '1_week',
                'name' => '1 week',
                'type' => 'time_unlimited',
                'price' => 400,
                'duration_days' => 7,
            ],
        ],
        'sambaza_line' => '2547XXXXXXXX',
        'sambaza_ussd_prompts' => ['prompt one', 'prompt two'],
    ];

    Cache::put(
        sprintf('autoreach.subscription_plans.%d.%s', $user->id, sha1('raw-device-token')),
        $cachedPlans,
        now()->addMinutes(2),
    );

    $this->actingAs($user);

    $requestCount = 0;

    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        return Http::response([], 500);
    });

    $response = Livewire::test('plans');

    $response->assertSee('Subscriptions');
    $response->assertSee('1 week');
    $response->assertSet('loaded', true);
    $response->assertSet('plans.0.name', '1 week');

    expect($requestCount)->toBe(0);
});

test('plans recover the device token on unauthorized responses and retry successfully', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'old-device-token-401',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user);

    Http::fake(function ($request) {
        if ($request->url() === 'https://backend.statum.co.ke/api/v1/auth/device/token/recover') {
            return Http::response([
                'status' => 'success',
                'message' => 'Device token recovered.',
                'device_token' => 'new-device-token-401',
                'device_id' => 42,
                'bhc_code' => 'BHC-ZXCVB',
            ], 200);
        }

        if ($request->url() === 'https://backend.statum.co.ke/api/v1/subscription/plans/hybrid'
            && $request->hasHeader('Authorization', 'Bearer old-device-token-401')) {
            return Http::response([
                'status' => 'failed',
                'message' => 'Unauthorized device token',
            ], 401);
        }

        if ($request->url() === 'https://backend.statum.co.ke/api/v1/subscription/plans/hybrid'
            && $request->hasHeader('Authorization', 'Bearer new-device-token-401')) {
            return Http::response([
                'status' => 'ok',
                'plans' => [
                    [
                        'id' => 5,
                        'code' => '1_week',
                        'name' => '1 week',
                        'type' => 'time_unlimited',
                        'price' => 400,
                        'duration_days' => 7,
                    ],
                ],
                'sambaza_line' => '2547XXXXXXXX',
                'sambaza_ussd_prompts' => [],
            ], 200);
        }

        return Http::response([], 404);
    });

    $response = Livewire::test('plans')
        ->call('loadPlans');

    $response->assertHasNoErrors();
    $response->assertSet('plans.0.name', '1 week');
    $response->assertSee('KES 400');

    expect($user->refresh()->bingwaDeviceRegistration?->device_token)->toBe('new-device-token-401');

    Http::assertSentCount(3);
});

test('plans page caches the backend response for repeat loads', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user);

    $requestCount = 0;

    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        return Http::response([
            'status' => 'ok',
            'plans' => [
                [
                    'id' => 5,
                    'code' => '1_week',
                    'name' => '1 week',
                    'type' => 'time_unlimited',
                    'price' => 400,
                    'duration_days' => 7,
                ],
            ],
            'sambaza_line' => '2547XXXXXXXX',
            'sambaza_ussd_prompts' => [],
        ], 200);
    });

    Livewire::test('plans')
        ->call('loadPlans')
        ->call('loadPlans');

    expect($requestCount)->toBe(1);
});

test('plans refresh bypasses the cached backend response', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user);

    $requestCount = 0;

    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        return Http::response([
            'status' => 'ok',
            'plans' => [
                [
                    'id' => 5,
                    'code' => '1_week',
                    'name' => '1 week',
                    'type' => 'time_unlimited',
                    'price' => 400,
                    'duration_days' => 7,
                ],
            ],
            'sambaza_line' => '2547XXXXXXXX',
            'sambaza_ussd_prompts' => [],
        ], 200);
    });

    Livewire::test('plans')
        ->call('loadPlans')
        ->call('refreshPlans');

    expect($requestCount)->toBe(2);
});

test('a plan can be selected in the mobile ui', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user);

    Http::fake([
        'backend.statum.co.ke/api/v1/subscription/plans/hybrid' => Http::response([
            'status' => 'ok',
            'plans' => [
                [
                    'id' => 5,
                    'code' => '1_week',
                    'name' => '1 week',
                    'type' => 'time_unlimited',
                    'price' => 400,
                    'duration_days' => 7,
                ],
            ],
        ], 200),
    ]);

    $response = Livewire::test('plans')
        ->call('loadPlans')
        ->call('selectPlan', 5);

    $response->assertSet('selectedPlanId', 5);
    $response->assertSet('purchaseInFlight', false);
    $response->assertSee('SELECTED');
});

test('plans still load from the backend when a subscription is already active', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $user->plans()->create([
        'backend_plan_id' => 5,
        'code' => '1_week',
        'name' => '1 week',
        'type' => 'time_unlimited',
        'price' => 400,
        'duration_days' => 7,
        'expires_at' => now()->addDays(7),
        'is_active' => true,
    ]);

    $this->actingAs($user);

    Http::fake([
        'backend.statum.co.ke/api/v1/subscription/plans/hybrid' => Http::response([
            'status' => 'ok',
            'plans' => [
                [
                    'id' => 5,
                    'code' => '1_week',
                    'name' => '1 week',
                    'type' => 'time_unlimited',
                    'price' => 400,
                    'duration_days' => 7,
                ],
                [
                    'id' => 7,
                    'code' => '1_month',
                    'name' => '1 month',
                    'type' => 'time_unlimited',
                    'price' => 1200,
                    'duration_days' => 30,
                ],
            ],
            'sambaza_line' => '2547XXXXXXXX',
            'sambaza_ussd_prompts' => [],
        ], 200),
    ]);

    $response = Livewire::test('plans')
        ->call('loadPlans');

    $response->assertHasNoErrors();
    $response->assertSet('plans.0.name', '1 week');
    $response->assertSee('1 week');
    $response->assertSee('1 month');
    $response->assertSee('ACTIVE');
    $response->assertSee('LOCKED');
});

test('the purchase button is scoped to the sambaza action only', function () {
    $view = File::get(base_path('resources/views/components/⚡plans.blade.php'));

    expect($view)
        ->toContain('wire:click="initiateSambaza"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="initiateSambaza"')
        ->toContain('wire:loading.remove wire:target="initiateSambaza"')
        ->toContain('wire:loading wire:target="initiateSambaza"');
});

test('usage pack plans show ussd requests instead of duration', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user);

    Http::fake([
        'backend.statum.co.ke/api/v1/subscription/plans/hybrid' => Http::response([
            'status' => 'ok',
            'plans' => [
                [
                    'id' => 7,
                    'code' => 'test_1',
                    'name' => 'Test',
                    'type' => 'usage_pack',
                    'price' => 10,
                    'ussd_requests_included' => 10,
                    'duration_days' => null,
                ],
            ],
        ], 200),
    ]);

    $response = Livewire::test('plans')
        ->call('loadPlans');

    $response->assertSee('USSD requests');
    $response->assertSee('10');
    $response->assertDontSee('Duration');
});

test('plans page surfaces backend failures cleanly', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user);

    Http::fake([
        'backend.statum.co.ke/api/v1/subscription/plans/hybrid' => Http::response([
            'status' => 'failed',
            'message' => 'Device type must be hybridApp.',
        ], 403),
    ]);

    $response = Livewire::test('plans')
        ->call('loadPlans');

    $response->assertHasNoErrors();
    $response->assertSet('plans', []);
    $response->assertSee('Device type must be hybridApp.');
});

test('successful local subscription purchase queues backend purchase sync', function () {
    Queue::fake();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user);

    Livewire::test('plans')
        ->set('plans', [
            [
                'id' => 5,
                'code' => 'token_300',
                'name' => '300 USSD Requests',
                'type' => 'usage_pack',
                'price' => 50,
                'duration_days' => null,
                'ussd_requests_included' => 300,
            ],
        ])
        ->call('saveSubscription', 5, 'MPE123456')
        ->assertSet('selectedPlanId', null);

    $plan = $user->plans()->first();

    expect($plan)->not->toBeNull();
    expect($plan?->code)->toBe('token_300');
    expect($plan?->remote_purchase_synced_at)->toBeNull();

    Queue::assertPushed(SyncRemoteSubscriptionPurchaseJob::class, function (SyncRemoteSubscriptionPurchaseJob $job) use ($plan): bool {
        return $job->planId === $plan?->id
            && $job->paymentReference === 'MPE123456';
    });
});
