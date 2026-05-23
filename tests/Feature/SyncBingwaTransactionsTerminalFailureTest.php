<?php

use App\Actions\Autoreach\FetchNextBingwaJobs;
use App\Jobs\UpdateRemoteTransactionStatusJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('queues a remote status update when a backend transaction fails during sync', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Offer::factory()->for($user)->create([
        'name' => 'Test 1 day',
        'category' => 'data',
        'price' => 20,
        'is_active' => true,
    ]);

    Http::fake([
        'backend.statum.co.ke/api/v1/jobs/next/data_bundles*' => Http::response([
            'transaction_id' => 91,
            'mpesa_code' => 'UDH9Z12J9A',
            'sender_phone' => '0719704791',
            'sender_name' => 'failed sync user',
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

    $result = app(FetchNextBingwaJobs::class)->sync($user, 1);

    expect($result)->toMatchArray([
        'synced' => 0,
        'skipped' => 0,
        'failed' => 1,
    ]);

    $transaction = Transaction::query()->where('transaction_id', '91')->first();

    expect($transaction)->not->toBeNull();
    expect($transaction?->status)->toBe('failed');
    expect($transaction?->processed_at)->not->toBeNull();
    expect($transaction?->status_desc)->toBe('No active subscription plan found.');

    Queue::assertPushed(UpdateRemoteTransactionStatusJob::class, function (UpdateRemoteTransactionStatusJob $job): bool {
        return $job->remoteTransactionId === '91'
            && $job->status === 'failed'
            && $job->failureCode === 'SYSTEM_ERROR'
            && $job->deviceToken === 'raw-device-token';
    });
});
