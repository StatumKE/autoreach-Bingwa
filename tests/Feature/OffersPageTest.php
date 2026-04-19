<?php

use App\Models\Offer;
use App\Models\User;
use Livewire\Livewire;

test('offers page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('offers'))
        ->assertOk()
        ->assertSee('My Offers')
        ->assertSee('Manage synchronized telco offers');
});

test('offers can be created', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('offers')
        ->call('createOffer')
        ->assertDispatched('modal-show', name: 'offer-form')
        ->set('name', '1.25 GB Midnight Bundles')
        ->set('category', 'data')
        ->set('price', 50)
        ->set('ussd_code', '*180*5*PN#')
        ->set('ussd_mode', 'express')
        ->set('is_active', true)
        ->call('saveOffer');

    $response->assertHasNoErrors();
    $response->assertSet('showForm', false);

    $this->assertDatabaseHas('offers', [
        'user_id' => $user->id,
        'name' => '1.25 GB Midnight Bundles',
        'category' => 'data',
        'price' => 50,
        'ussd_code' => '*180*5*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);
});

test('offers can be edited', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => 'Old Offer',
        'category' => 'sms',
        'price' => 25,
        'ussd_code' => '*123#',
        'ussd_mode' => 'advanced',
        'is_active' => false,
    ]);

    $this->actingAs($user);

    $response = Livewire::test('offers')
        ->call('editOffer', $offer->id)
        ->assertDispatched('modal-show', name: 'offer-form')
        ->set('name', 'Updated Offer')
        ->set('category', 'airtime')
        ->set('price', 75)
        ->set('ussd_code', '*544#')
        ->set('ussd_mode', 'express')
        ->set('is_active', true)
        ->call('saveOffer');

    $response->assertHasNoErrors();

    $this->assertDatabaseHas('offers', [
        'id' => $offer->id,
        'user_id' => $user->id,
        'name' => 'Updated Offer',
        'category' => 'airtime',
        'price' => 75,
        'ussd_code' => '*544#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);
});

test('offers can be deleted', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create();

    $this->actingAs($user);

    $response = Livewire::test('offers')
        ->call('confirmDeleteOffer', $offer->id)
        ->call('deleteOffer');

    $response->assertHasNoErrors();
    $response->assertSet('deletingOfferId', null);

    $this->assertDatabaseMissing('offers', [
        'id' => $offer->id,
    ]);
});

test('offers can be filtered by category', function () {
    $user = User::factory()->create();

    Offer::factory()->for($user)->create([
        'name' => 'Data Offer',
        'category' => 'data',
    ]);

    Offer::factory()->for($user)->create([
        'name' => 'SMS Offer',
        'category' => 'sms',
    ]);

    $this->actingAs($user);

    $response = Livewire::test('offers')
        ->call('setCategoryFilter', 'sms');

    $response->assertSee('SMS Offer');
    $response->assertDontSee('Data Offer');
});
