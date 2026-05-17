<?php

use App\Models\AutoRenewal;
use App\Models\Offer;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the auto renewals page', function () {
    $response = $this->get(route('auto-renewals'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can view the auto renewals page', function () {
    $user = User::factory()->create();
    Offer::factory()->for($user)->create([
        'name' => 'Weekend Data',
        'price' => 250,
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('auto-renewals'));

    $response->assertOk();
    $response->assertSee('Auto Renewals');
    $response->assertSee('Scheduled Awards');
    $response->assertSee('Create');
});

test('auto renewals load page data after the initial shell renders', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => 'Weekend Data',
        'price' => 250,
        'is_active' => true,
    ]);

    $this->actingAs($user);

    Livewire::test('auto-renewals')
        ->call('loadPage')
        ->assertSet('offerId', $offer->id)
        ->assertSee('Scheduled Awards');
});

test('auto renewals can be scheduled from the page', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => 'Night Airtime Pack',
        'price' => 120,
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $response = Livewire::test('auto-renewals')
        ->set('customerPhone', '0712345678')
        ->set('offerId', $offer->id)
        ->set('scheduledDate', now()->addDay()->format('Y-m-d'))
        ->set('scheduledTime', '11:24')
        ->set('autoRenew', true)
        ->set('renewDays', '7')
        ->call('scheduleRenewal');

    $response->assertHasNoErrors();

    $this->assertDatabaseHas('auto_renewals', [
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'customer_phone' => '0712345678',
        'auto_renew' => true,
        'renew_days' => 7,
        'status' => 'scheduled',
    ]);
});

test('scheduled auto renewals can be cancelled', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => 'Monthly Bundle',
        'price' => 300,
        'is_active' => true,
    ]);

    $renewal = AutoRenewal::factory()->for($user)->for($offer)->create([
        'status' => 'scheduled',
        'scheduled_for' => now()->addDay(),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('auto-renewals');
    $response->call('cancelAutoRenewal', $renewal->id);

    $response->assertHasNoErrors();

    $this->assertDatabaseHas('auto_renewals', [
        'id' => $renewal->id,
        'status' => 'cancelled',
    ]);
});
