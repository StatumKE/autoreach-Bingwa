<?php

use App\Actions\Autoreach\PersistBingwaTransaction;
use App\Livewire\BroadcastListener;
use App\Models\BingwaDeviceRegistration;
use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

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

    $this->offer = Offer::factory()->for($this->user)->create([
        'name' => 'Weekly Airtime',
        'category' => 'airtime',
        'price' => 20,
        'is_active' => true,
    ]);
});

test('it persists a broadcast queued transaction using the local device registration', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-17 20:34:15', 'Africa/Nairobi'));

    $result = app(PersistBingwaTransaction::class)->persist($this->user, [
        'transaction_id' => 'TX-123',
        'mpesa_code' => 'MPESA123',
        'sender_phone' => '0712000000',
        'sender_name' => 'Jane Doe',
        'amount' => 20,
        'offer_name' => 'Weekly Airtime',
        'offer_type' => 'airtime',
        'matched_offer' => [
            'offer_key' => 'weekly_airtime',
            'offer_name' => 'Weekly Airtime',
        ],
        'occurred_at' => '2026-04-17T17:34:15+00:00',
        'service' => 'mpesa',
    ]);

    Carbon::setTestNow();

    expect($result['skipped'])->toBeFalse();
    expect($result['transaction'])->not->toBeNull();
    expect($result['transaction']?->transaction_id)->toBe('TX-123');
    expect($result['transaction']?->offer_id)->toBe($this->offer->id);
    expect($result['transaction']?->status)->toBe('queued');
    expect($result['transaction']?->occurred_at?->timezoneName)->toBe('Africa/Nairobi');
    expect($result['transaction']?->occurred_at?->format('Y-m-d H:i:s'))->toBe('2026-04-17 20:34:15');

    $this->assertDatabaseHas('transactions', [
        'transaction_id' => 'TX-123',
        'user_id' => $this->user->id,
        'offer_id' => $this->offer->id,
        'status' => 'queued',
        'status_desc' => 'Pulled from backend job queue.',
    ]);
});

test('it skips finalized transactions instead of overwriting them', function () {
    Transaction::factory()->for($this->user)->create([
        'transaction_id' => 'TX-LOCKED',
        'offer_id' => $this->offer->id,
        'mpesa_code' => 'LOCKED123',
        'sender_phone' => '0712000000',
        'amount' => 20,
        'offer_name' => 'Weekly Airtime',
        'offer_type' => 'airtime',
        'status' => 'completed',
        'status_desc' => 'Already processed.',
        'processed_at' => now(),
    ]);

    $result = app(PersistBingwaTransaction::class)->persist($this->user, [
        'transaction_id' => 'TX-LOCKED',
        'mpesa_code' => 'NEW123',
        'sender_phone' => '0712999999',
        'sender_name' => 'Someone Else',
        'amount' => 50,
        'offer_name' => 'Another Offer',
        'offer_type' => 'airtime',
        'occurred_at' => '2026-04-17T17:34:15+00:00',
        'service' => 'mpesa',
    ]);

    expect($result['skipped'])->toBeTrue();
    expect($result['transaction'])->toBeNull();
    expect(Transaction::query()->where('transaction_id', 'TX-LOCKED')->first()?->mpesa_code)->toBe('LOCKED123');
});

test('the broadcast listener subscribes to the saved bhc code channel', function () {
    $this->actingAs($this->user);

    $listener = app(BroadcastListener::class);

    expect($listener->getListeners())->toMatchArray([
        'echo-private:autoreach-bingwa.devices.BHC-ZXCVB,transaction.queued' => 'transactionQueued',
    ]);
});
