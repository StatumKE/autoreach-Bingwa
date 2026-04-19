<?php

use App\Actions\Autoreach\FetchNextBingwaJobs;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('bingwa transaction sync pulls jobs from all backend queues concurrently', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Http::fake([
        'backend.statum.co.ke/api/v1/jobs/next/data_bundles*' => Http::response([
            'transaction_id' => 70,
            'mpesa_code' => 'UDH9Z12J5E',
            'sender_phone' => '0719704751',
            'sender_name' => 'egline jerop',
            'amount' => 20,
            'offer_name' => 'Test 1 day',
            'offer_type' => 'data_bundles',
            'matched_offer' => [
                'offer_local_id' => '9',
                'offer_key' => 'test_1_day',
                'offer_name' => 'Test 1 day',
                'offer_type' => 'data_bundles',
                'offer_amount' => 20,
            ],
            'occurred_at' => '2026-04-17T20:34:15+03:00',
            'balance' => [
                'device_account_id' => 1,
                'tokens' => [
                    'included' => 0,
                    'consumed' => 0,
                    'balance' => 0,
                    'remaining' => 0,
                ],
            ],
        ], 200),
        'backend.statum.co.ke/api/v1/jobs/next/sms*' => Http::response('', 204),
        'backend.statum.co.ke/api/v1/jobs/next/airtime*' => Http::response([
            'count' => 2,
            'jobs' => [
                [
                    'transaction_id' => 71,
                    'mpesa_code' => 'UDH9Z12J5F',
                    'sender_phone' => '0719704752',
                    'sender_name' => 'john doe',
                    'amount' => 50,
                    'offer_name' => 'Weekly Airtime',
                    'offer_type' => 'airtime',
                    'matched_offer' => null,
                    'occurred_at' => '2026-04-17T20:35:01+03:00',
                ],
                [
                    'transaction_id' => 72,
                    'mpesa_code' => 'UDH9Z12J5G',
                    'sender_phone' => '0719704753',
                    'sender_name' => 'mary jane',
                    'amount' => 15,
                    'offer_name' => 'SMS Pack',
                    'offer_type' => 'airtime',
                    'matched_offer' => null,
                    'occurred_at' => '2026-04-17T20:36:01+03:00',
                ],
            ],
            'balance' => [
                'device_account_id' => 1,
                'tokens' => [
                    'included' => 0,
                    'consumed' => 0,
                    'balance' => 0,
                    'remaining' => 0,
                ],
            ],
        ], 200),
    ]);

    $action = app(FetchNextBingwaJobs::class);
    $result = $action->sync($user);

    expect($result['synced'])->toBe(3);
    expect($result['failed'])->toBe(0);

    Http::assertSentCount(3);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/api/v1/jobs/next/')
            && $request->hasHeader('Authorization', 'Bearer raw-device-token')
            && $request->method() === 'GET';
    });

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'transaction_id' => '70',
        'mpesa_code' => 'UDH9Z12J5E',
        'sender_phone' => '0719704751',
        'sender_name' => 'egline jerop',
        'offer_name' => 'Test 1 day',
        'offer_type' => 'data_bundles',
        'status' => 'queued',
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'transaction_id' => '71',
        'offer_name' => 'Weekly Airtime',
    ]);

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'transaction_id' => '72',
        'offer_name' => 'SMS Pack',
    ]);
});
