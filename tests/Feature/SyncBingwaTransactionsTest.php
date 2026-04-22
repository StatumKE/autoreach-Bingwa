<?php

use App\Actions\Autoreach\FetchNextBingwaJobs;
use App\Models\BingwaDeviceRegistration;
use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $this->user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->user->plans()->create([
        'code' => 'starter_pack',
        'name' => 'Starter Pack',
        'type' => 'usage_pack',
        'price' => 99,
        'ussd_requests_included' => 10,
        'ussd_counter' => 0,
        'is_active' => true,
    ]);

    $this->dataOffer = Offer::factory()->for($this->user)->create([
        'name' => 'Test 1 day',
        'category' => 'data',
        'price' => 20,
        'is_active' => true,
    ]);

    $this->airtimeOffer = Offer::factory()->for($this->user)->create([
        'name' => 'Weekly Airtime',
        'category' => 'airtime',
        'price' => 50,
        'is_active' => true,
    ]);

    $this->smsOffer = Offer::factory()->for($this->user)->create([
        'name' => 'SMS Pack',
        'category' => 'sms',
        'price' => 15,
        'is_active' => true,
    ]);

    $this->action = app(FetchNextBingwaJobs::class);
});

test('bingwa transaction sync clamps low limits to one and stores single-job responses without balance', function () {
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
                'canonical_offer_id' => 9,
            ],
            'occurred_at' => '2026-04-17T17:34:15+00:00',
        ], 200),
        'backend.statum.co.ke/api/v1/jobs/next/sms*' => Http::response('', 204),
        'backend.statum.co.ke/api/v1/jobs/next/airtime*' => Http::response('', 204),
    ]);

    $result = $this->action->sync($this->user, 0);

    expect($result)->toMatchArray([
        'synced' => 1,
        'skipped' => 0,
        'failed' => 0,
    ]);

    Http::assertSentCount(3);

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/api/v1/jobs/next/')
            && $request->hasHeader('Authorization', 'Bearer raw-device-token')
            && $request->method() === 'GET'
            && ($query['limit'] ?? null) === '1';
    });

    $transaction = Transaction::query()->where('transaction_id', '70')->first();

    expect($transaction)->not->toBeNull();
    expect($transaction?->offer_id)->toBe($this->dataOffer->id);
    expect($transaction?->status)->toBe('queued');
    expect($transaction?->occurred_at?->timezoneName)->toBe('Africa/Nairobi');
    expect($transaction?->occurred_at?->format('Y-m-d H:i:s'))->toBe('2026-04-17 20:34:15');
    expect($transaction?->matched_offer)->toMatchArray([
        'offer_key' => 'test_1_day',
        'canonical_offer_id' => 9,
    ]);
    expect($transaction?->balance)->toBeNull();
});

test('bingwa transaction sync stores batch responses without balance', function () {
    Http::fake([
        'backend.statum.co.ke/api/v1/jobs/next/data_bundles*' => Http::response('', 204),
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
        ], 200),
    ]);

    $result = $this->action->sync($this->user, 2);

    expect($result)->toMatchArray([
        'synced' => 2,
        'skipped' => 0,
        'failed' => 0,
    ]);

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/api/v1/jobs/next/')
            && $request->hasHeader('Authorization', 'Bearer raw-device-token')
            && ($query['limit'] ?? null) === '2';
    });

    expect(Transaction::query()->where('transaction_id', '71')->first()?->offer_id)->toBe($this->airtimeOffer->id);
    expect(Transaction::query()->where('transaction_id', '72')->first()?->offer_id)->toBe($this->smsOffer->id);
    expect(Transaction::query()->where('transaction_id', '71')->first()?->balance)->toBeNull();
    expect(Transaction::query()->where('transaction_id', '72')->first()?->balance)->toBeNull();
});

test('bingwa transaction sync does not requeue locally finalized transactions', function () {
    $processedAt = now()->subMinute();
    Transaction::factory()->for($this->user)->create([
        'transaction_id' => '70',
        'offer_id' => $this->dataOffer->id,
        'mpesa_code' => 'UDH9Z12J5E',
        'sender_phone' => '0719704751',
        'amount' => 20,
        'offer_name' => 'Test 1 day',
        'offer_type' => 'data_bundles',
        'status' => 'failed',
        'status_desc' => 'Invalid choice. Try again.',
        'processed_at' => $processedAt,
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
            'matched_offer' => null,
            'occurred_at' => '2026-04-17T17:34:15+00:00',
        ], 200),
        'backend.statum.co.ke/api/v1/jobs/next/sms*' => Http::response('', 204),
        'backend.statum.co.ke/api/v1/jobs/next/airtime*' => Http::response('', 204),
    ]);

    $result = $this->action->sync($this->user, 1);

    expect($result)->toMatchArray([
        'synced' => 0,
        'skipped' => 1,
        'failed' => 0,
    ]);

    $transaction = Transaction::query()->where('transaction_id', '70')->first();

    expect($transaction?->status)->toBe('failed');
    expect($transaction?->status_desc)->toBe('Invalid choice. Try again.');
    expect($transaction?->processed_at?->format('Y-m-d H:i:s'))->toBe($processedAt->format('Y-m-d H:i:s'));
});

test('bingwa transaction sync clamps high limits to ten and treats stopped devices as failed polls', function () {
    Http::fake([
        'backend.statum.co.ke/api/v1/jobs/next/data_bundles*' => Http::response([
            'message' => 'Device has been stopped.',
        ], 403),
        'backend.statum.co.ke/api/v1/jobs/next/sms*' => Http::response('', 204),
        'backend.statum.co.ke/api/v1/jobs/next/airtime*' => Http::response('', 204),
    ]);

    $result = $this->action->sync($this->user, 99);

    expect($result)->toMatchArray([
        'synced' => 0,
        'skipped' => 0,
        'failed' => 1,
    ]);

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/api/v1/jobs/next/')
            && $request->hasHeader('Authorization', 'Bearer raw-device-token')
            && ($query['limit'] ?? null) === '10';
    });

    expect(Transaction::query()->count())->toBe(0);
});
