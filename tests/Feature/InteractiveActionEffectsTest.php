<?php

use App\Models\Offer;
use App\Models\User;
use Laravel\Fortify\Features;
use Livewire\Livewire;

test('offers page shows loading feedback for save and delete actions', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('offers'))
        ->assertOk()
        ->assertSee('wire:target="saveOffer"', false)
        ->assertSee('Saving…')
        ->assertSee('wire:target="deleteOffer"', false)
        ->assertSee('Deleting…');
});

test('plans page shows loading feedback for refresh and purchase actions', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('plans')
        ->set('loaded', true)
        ->set('plans', [
            [
                'id' => 1,
                'name' => 'Demo Plan',
                'type' => 'usage_pack',
                'price' => 100,
                'duration_days' => 30,
                'ussd_requests_included' => 100,
            ],
        ])
        ->set('selectedPlanId', 1)
        ->set('purchaseInFlight', true)
        ->set('sambazaLine', '254700000000')
        ->assertSee('Refresh')
        ->assertSee('Processing purchase')
        ->assertSee('Keep the app open while your phone handles the USSD session.');
});

test('quick dial page shows loading feedback for award actions', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => '1 GB Data',
        'ussd_code' => '*180*5*PN#',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    Livewire::test('quick-dials')
        ->call('loadPage')
        ->set('customerPhone', '0712345678')
        ->call('prepareAwardOffer', $offer->id)
        ->assertSee('wire:target="awardOffer"', false)
        ->assertSee('Sending…');
});

test('dashboard refresh action shows loading feedback', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('wire:target="refreshData"', false)
        ->assertSee('wire:loading.attr="disabled"', false);
});

test('authenticated mobile shell does not include broadcast bootstrap', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('__autoreachBroadcasting')
        ->assertDontSee('broadcast-listener');
});

test('two factor settings actions show loading feedback', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('wire:target="showVerificationIfNecessary"', false)
        ->assertSee('Loading…')
        ->assertDontSee('wire:target="disable"', false)
        ->assertDontSee('wire:target="regenerateRecoveryCodes"', false);
});

test('enabled two factor settings actions show loading feedback', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('wire:target="disable"', false)
        ->assertSee('Disabling…')
        ->assertSee('wire:target="regenerateRecoveryCodes"', false)
        ->assertSee('Regenerating…');
});
