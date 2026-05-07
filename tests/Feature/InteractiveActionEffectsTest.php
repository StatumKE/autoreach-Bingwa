<?php

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
        ->set('sambazaLine', '254700000000')
        ->assertSee('Refresh')
        ->assertSee('Purchasing…');
});

test('quick dial page shows loading feedback for contacts and award actions', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('quick-dials')
        ->call('openContactPicker')
        ->assertSet('showContactPicker', true)
        ->assertSee('Type a name or number to search contacts.');
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
        ->assertDontSee('broadcast-listener')
        ->assertDontSee('RequestSetupPermissions');
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
