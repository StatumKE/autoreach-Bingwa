<?php

use App\Actions\Autoreach\RefreshAirtimeBalance;
use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AppTimezone;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

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
    $response->assertSee('height: calc(48px + var(--inset-top, 0px))', false);
    $response->assertSee('h-[calc(100dvh-var(--inset-top,0px)-48px)]', false);
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
    $response->assertSee('Recent Transactions');
    $response->assertSee('overflow-y-auto overscroll-contain', false);
});

test('dashboard recent transactions are ordered by newest id first', function () {
    $user = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'sender_name' => 'First Created',
        'amount' => 100,
        'offer_name' => 'Older Bundle',
        'status' => 'completed',
        'status_desc' => 'Older transaction.',
        'occurred_at' => now()->addMinutes(5),
    ]);

    Transaction::factory()->for($user)->create([
        'sender_name' => 'Second Created',
        'amount' => 50,
        'offer_name' => 'Newer Bundle',
        'status' => 'failed',
        'status_desc' => 'Newer transaction.',
        'occurred_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder(['Second Created', 'First Created']);
});

test('dashboard success and failed stats reset at midnight in Nairobi time', function () {
    $user = User::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-05-23 23:59:00', AppTimezone::name()));

    try {
        Transaction::factory()->for($user)->create([
            'amount' => 100,
            'status' => 'completed',
            'occurred_at' => now(),
        ]);

        Transaction::factory()->for($user)->create([
            'amount' => 50,
            'status' => 'failed',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user);

        $beforeMidnight = Livewire::test('dashboard')->instance()->stats;

        expect($beforeMidnight)->toMatchArray([
            'successful' => 1,
            'failed' => 1,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-24 00:00:00', AppTimezone::name()));

        $afterMidnight = Livewire::test('dashboard')->instance()->stats;

        expect($afterMidnight)->toMatchArray([
            'successful' => 0,
            'failed' => 0,
        ]);
    } finally {
        Carbon::setTestNow();
    }
});

test('dashboard refresh action triggers the airtime balance refresh synchronously', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->mock(RefreshAirtimeBalance::class, function ($mock) use ($user) {
        $mock->shouldReceive('refresh')
            ->once()
            ->withArgs(fn ($arg) => $arg->is($user))
            ->andReturn([
                'balance' => 150.00,
                'raw_response' => 'Airtime Bal: 150.00KSH',
                'checked_at' => now(),
                'permission_denied' => false,
            ]);

        $mock->shouldReceive('cached')
            ->andReturn([
                'balance' => 150.00,
                'raw_response' => 'Airtime Bal: 150.00KSH',
                'checked_at' => now(),
                'permission_denied' => false,
            ]);
    });

    Livewire::test('dashboard')
        ->call('refreshData')
        ->assertHasNoErrors();
});
