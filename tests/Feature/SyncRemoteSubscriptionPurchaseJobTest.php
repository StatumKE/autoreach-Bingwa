<?php

use App\Jobs\SyncRemoteSubscriptionPurchaseJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('syncs a successful local subscription purchase to the backend', function (): void {
    config(['services.autoreach.backend_url' => 'https://backend.example.test']);

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $plan = Plan::factory()->create([
        'user_id' => $user->id,
        'backend_plan_id' => 5,
        'code' => 'token_300',
        'name' => '300 USSD Requests',
        'type' => 'usage_pack',
        'price' => 50,
        'remote_subscription_id' => null,
        'remote_purchase_synced_at' => null,
        'remote_purchase_response' => null,
    ]);

    Http::fake([
        'backend.example.test/api/v1/subscription/purchase' => Http::response([
            'status' => 'accepted',
            'subscription_id' => 123,
            'plan' => [
                'code' => 'token_300',
                'name' => '300 USSD Requests',
                'type' => 'usage_pack',
                'price' => 50,
            ],
            'balance' => [],
        ], 201),
    ]);

    (new SyncRemoteSubscriptionPurchaseJob($plan->id, 'MPE123456'))->handle();

    $fresh = $plan->fresh();

    expect($fresh?->remote_subscription_id)->toBe(123);
    expect($fresh?->remote_purchase_synced_at)->not->toBeNull();
    expect($fresh?->remote_purchase_response['status'] ?? null)->toBe('accepted');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://backend.example.test/api/v1/subscription/purchase'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer raw-device-token')
            && $request['plan_code'] === 'token_300'
            && $request['promo_code'] === null
            && $request['payment_reference'] === 'MPE123456';
    });
});

it('does not post a subscription purchase that is already synced', function (): void {
    $plan = Plan::factory()->create([
        'remote_subscription_id' => 123,
        'remote_purchase_synced_at' => now(),
        'remote_purchase_response' => ['status' => 'accepted'],
    ]);

    Http::fake();

    (new SyncRemoteSubscriptionPurchaseJob($plan->id, 'MPE123456'))->handle();

    Http::assertNothingSent();
});

it('leaves the local plan unsynced when the backend rejects the purchase', function (): void {
    config(['services.autoreach.backend_url' => 'https://backend.example.test']);

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $plan = Plan::factory()->create([
        'user_id' => $user->id,
        'code' => 'missing_plan',
        'remote_subscription_id' => null,
        'remote_purchase_synced_at' => null,
        'remote_purchase_response' => null,
    ]);

    Http::fake([
        'backend.example.test/api/v1/subscription/purchase' => Http::response([
            'status' => 'failed',
            'message' => 'The selected subscription plan is invalid or inactive.',
            'errors' => [
                'plan_code' => ['The selected subscription plan is invalid or inactive.'],
            ],
        ], 422),
    ]);

    expect(fn () => (new SyncRemoteSubscriptionPurchaseJob($plan->id))->handle())
        ->toThrow(RuntimeException::class);

    $fresh = $plan->fresh();

    expect($fresh?->remote_subscription_id)->toBeNull();
    expect($fresh?->remote_purchase_synced_at)->toBeNull();
    expect($fresh?->remote_purchase_response)->toBeNull();
});
