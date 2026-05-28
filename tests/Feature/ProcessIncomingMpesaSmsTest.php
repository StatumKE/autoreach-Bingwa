<?php

use App\Actions\Autoreach\ExecuteBingwaUssd;
use App\Actions\Autoreach\ProcessIncomingMpesaSms;
use App\Jobs\UpdateRemoteTransactionStatusJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\DeviceSetting;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AppTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

it('creates a local M-Pesa SMS transaction and processes it immediately', function (): void {
    Queue::fake();
    $user = registeredIncomingSmsUser();
    $offer = Offer::factory()->for($user)->create([
        'name' => 'M-Pesa Matched Bundle',
        'category' => 'data',
        'price' => 99,
        'ussd_code' => '*180*5*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);
    Plan::factory()->for($user)->create(['is_active' => true, 'type' => 'time_unlimited']);

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn (array $payload): bool => ($payload['code'] ?? null) === '*180*5*0712345678#')
            ->andReturn([
                'success' => true,
                'message' => 'USSD request completed.',
                'raw_response' => null,
            ]);
    });

    $result = app(ProcessIncomingMpesaSms::class)->process(incomingSmsPayload());
    $transaction = Transaction::query()->where('mpesa_code', 'UBO817Y3ZG')->first();

    expect($result['status'])->toBe('processed')
        ->and($transaction)->not->toBeNull()
        ->and($transaction->offer_id)->toBe($offer->id)
        ->and($transaction->transaction_id)->toBe('SMS-UBO817Y3ZG')
        ->and($transaction->sender_phone)->toBe('0712345678')
        ->and($transaction->sender_name)->toBe('JOHN DOE')
        ->and($transaction->amount)->toBe('99.00')
        ->and($transaction->status)->toBe('completed')
        ->and($transaction->matched_offer)->toMatchArray([
            'source' => 'mpesa_sms',
            'offer_local_id' => (string) $offer->id,
            'mpesa_code' => 'UBO817Y3ZG',
        ]);

    Queue::assertNotPushed(UpdateRemoteTransactionStatusJob::class);
});

it('processes date-first M-Pesa sms bodies with 01XXXXXXXX sender numbers', function (): void {
    $user = registeredIncomingSmsUser();
    $offer = Offer::factory()->for($user)->create([
        'name' => 'M-Pesa Matched Bundle',
        'category' => 'data',
        'price' => 10,
        'ussd_code' => '*180*5*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);
    Plan::factory()->for($user)->create(['is_active' => true, 'type' => 'time_unlimited']);

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(fn (array $payload): bool => ($payload['code'] ?? null) === '*180*5*0118959014#')
            ->andReturn([
                'success' => true,
                'message' => 'USSD request completed.',
                'raw_response' => null,
            ]);
    });

    $result = app(ProcessIncomingMpesaSms::class)->process(incomingSmsPayload(
        body: 'UEHAR4H11X Confirmed.on 17/5/26 at 7:38 PMKSH10.00 received from 254118959014 GLADys JEBIWOTT KIBET. New Account balance is KSH114,405.52. Transaction cst, KSH0.00.'
    ));
    $transaction = Transaction::query()->where('mpesa_code', 'UEHAR4H11X')->first();

    expect($result['status'])->toBe('processed')
        ->and($transaction)->not->toBeNull()
        ->and($transaction->offer_id)->toBe($offer->id)
        ->and($transaction->transaction_id)->toBe('SMS-UEHAR4H11X')
        ->and($transaction->sender_phone)->toBe('0118959014')
        ->and($transaction->sender_name)->toBe('GLADys JEBIWOTT KIBET')
        ->and($transaction->amount)->toBe('10.00')
        ->and($transaction->status)->toBe('completed')
        ->and($transaction->matched_offer)->toMatchArray([
            'source' => 'mpesa_sms',
            'offer_local_id' => (string) $offer->id,
            'mpesa_code' => 'UEHAR4H11X',
        ]);
});

it('stores incoming M-Pesa sms timestamps using the current Nairobi time', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-23 14:15:16', AppTimezone::name()));

    try {
        $user = registeredIncomingSmsUser();
        Plan::factory()->for($user)->create(['is_active' => true, 'type' => 'time_unlimited']);
        Offer::factory()->for($user)->create(['price' => 99, 'is_active' => true]);

        $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => 'USSD request completed.',
                    'raw_response' => null,
                ]);
        });

        app(ProcessIncomingMpesaSms::class)->process(incomingSmsPayload());
        $transaction = Transaction::query()->where('mpesa_code', 'UBO817Y3ZG')->first();

        expect($transaction)->not->toBeNull()
            ->and($transaction?->occurred_at?->timezone(AppTimezone::name())?->format('Y-m-d H:i:s'))->toBe('2026-05-23 14:15:16');
    } finally {
        Carbon::setTestNow();
    }
});

it('ignores duplicate M-Pesa codes without changing the existing transaction', function (): void {
    $user = registeredIncomingSmsUser();

    $existing = Transaction::factory()->for($user)->create([
        'transaction_id' => 'SMS-UBO817Y3ZG',
        'mpesa_code' => 'UBO817Y3ZG',
        'status' => 'completed',
        'status_desc' => 'Existing transaction.',
    ]);

    $result = app(ProcessIncomingMpesaSms::class)->process(incomingSmsPayload());

    expect($result['status'])->toBe('duplicate')
        ->and(Transaction::query()->where('mpesa_code', 'UBO817Y3ZG')->count())->toBe(1)
        ->and($existing->fresh()?->status_desc)->toBe('Existing transaction.');
});

it('saves a visible failed local transaction when no offer matches the amount', function (): void {
    $user = registeredIncomingSmsUser();
    Plan::factory()->for($user)->create(['is_active' => true, 'type' => 'time_unlimited']);
    Offer::factory()->for($user)->create(['price' => 50, 'is_active' => true]);

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('execute');
    });

    $result = app(ProcessIncomingMpesaSms::class)->process(incomingSmsPayload());
    $transaction = Transaction::query()->where('mpesa_code', 'UBO817Y3ZG')->first();

    expect($result['status'])->toBe('failed')
        ->and($result['message'])->toBe('no_matching_offer')
        ->and($transaction)->not->toBeNull()
        ->and($transaction->status)->toBe('failed')
        ->and($transaction->processed_at)->not->toBeNull()
        ->and($transaction->matched_offer)->toMatchArray(['source' => 'mpesa_sms']);
});

it('rejects untrusted senders by default and accepts them when allow all is enabled', function (): void {
    $user = registeredIncomingSmsUser();
    Plan::factory()->for($user)->create(['is_active' => true, 'type' => 'time_unlimited']);
    Offer::factory()->for($user)->create(['price' => 99, 'is_active' => true]);

    expect(app(ProcessIncomingMpesaSms::class)->process(incomingSmsPayload(sender: 'SAFARICOM'))['status'])->toBe('ignored');

    $user->deviceSetting()->update(['incoming_sms_allow_all_senders' => true]);

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'USSD request completed.',
                'raw_response' => null,
            ]);
    });

    expect(app(ProcessIncomingMpesaSms::class)->process(incomingSmsPayload(sender: 'SAFARICOM'))['status'])->toBe('processed');
});

it('reads all SIM slots by default but respects a selected incoming SMS SIM slot', function (): void {
    $user = registeredIncomingSmsUser();
    Plan::factory()->for($user)->create(['is_active' => true, 'type' => 'time_unlimited']);
    Offer::factory()->for($user)->create(['price' => 99, 'is_active' => true]);

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => true,
                'message' => 'USSD request completed.',
                'raw_response' => null,
            ]);
    });

    expect(app(ProcessIncomingMpesaSms::class)->process(incomingSmsPayload(simSlot: 'slot_2'))['status'])->toBe('processed');

    Transaction::query()->delete();
    $user->deviceSetting()->update(['incoming_sms_sim_slot' => 'slot_1']);

    expect(app(ProcessIncomingMpesaSms::class)->process(incomingSmsPayload(simSlot: 'slot_2')))->toMatchArray([
        'status' => 'ignored',
        'message' => 'sim_slot_not_enabled',
    ]);
});

it('accepts base64url payloads through the incoming SMS command', function (): void {
    $payload = incomingSmsPayload();
    $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    $this->artisan("bingwa:process-incoming-sms --payload={$encoded}")
        ->expectsOutputToContain('"status":"ignored"')
        ->assertExitCode(0);
});

function registeredIncomingSmsUser(array $settings = []): User
{
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-SMS',
    ]);

    DeviceSetting::factory()->for($user)->create([
        'incoming_sms_enabled' => true,
        'incoming_sms_allow_all_senders' => false,
        'incoming_sms_sim_slot' => 'all',
        ...$settings,
    ]);

    return $user;
}

/**
 * @return array<string, mixed>
 */
function incomingSmsPayload(
    string $sender = 'MPESA',
    string $simSlot = 'slot_1',
    ?string $body = null,
): array {
    return [
        'sender' => $sender,
        'body' => $body ?? 'UBO817Y3ZG Confirmed.You have received Ksh99.00 from JOHN DOE 0712345678 on 26/2/26 at 9:40 PM.',
        'received_at_ms' => 1772131200000,
        'subscription_id' => 1,
        'sim_slot' => $simSlot,
    ];
}
