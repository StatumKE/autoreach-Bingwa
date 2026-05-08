<?php

use App\Actions\Autoreach\RefreshAirtimeBalance;
use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('Autoreach Bingwa');
});

test('dashboard no longer renders a current balance card when historical balance data exists', function () {
    $user = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'amount' => 125,
        'status' => 'completed',
        'occurred_at' => now(),
        'balance' => [
            'airtime' => 420,
        ],
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Used Today');
    $response->assertDontSee('Current Balance');
});

test('dashboard shows the last cached airtime balance snapshot', function () {
    $user = User::factory()->create();

    DeviceSetting::factory()->for($user)->create([
        'primary_transaction_sim' => 'slot_1',
        'airtime_balance' => 13.44,
        'airtime_balance_raw_response' => 'Airtime Bal: 13.44KSH. Expire date:26-07-2026.',
        'airtime_balance_checked_at' => now(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Ksh 13.44');
});

test('airtime balance refresh creates device settings when missing', function () {
    $user = User::factory()->create();

    $result = app(RefreshAirtimeBalance::class)->cached($user);

    expect($result)->toBeArray();

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'primary_transaction_sim' => 'slot_1',
    ]);
});

test('dashboard recent transactions show ussd messages with status styling', function () {
    $user = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'sender_name' => 'Success Sender',
        'amount' => 100,
        'offer_name' => 'Success Bundle',
        'status' => 'completed',
        'status_desc' => 'USSD completed by carrier.',
        'occurred_at' => now(),
    ]);

    Transaction::factory()->for($user)->create([
        'sender_name' => 'Failed Sender',
        'amount' => 50,
        'offer_name' => 'Failed Bundle',
        'status' => 'failed',
        'status_desc' => 'USSD rejected by carrier.',
        'occurred_at' => now(),
    ]);

    Transaction::factory()->for($user)->create([
        'sender_name' => 'Queued Sender',
        'amount' => 25,
        'offer_name' => 'Queued Bundle',
        'status' => 'queued',
        'status_desc' => 'USSD waiting for processing.',
        'occurred_at' => now(),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('USSD');
    $response->assertSee('USSD completed by carrier.');
    $response->assertSee('USSD rejected by carrier.');
    $response->assertSee('USSD waiting for processing.');
    $response->assertSee('bg-green-50 text-green-800 ring-green-100', false);
    $response->assertSee('bg-rose-50 text-rose-800 ring-rose-100', false);
    $response->assertSee('bg-zinc-50 text-zinc-700 ring-zinc-200', false);
});
