<?php

use App\Actions\QuickDial\QuickDialContacts;
use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('quick dial page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('quick-dials'))
        ->assertOk()
        ->assertSee('Quick Dial')
        ->assertSee('Customer information')
        ->assertSee('Available offers')
        ->assertSee('Contacts')
        ->assertSee('Find contact')
        ->assertSee('Search your device contacts by name or number.');
});

test('quick dial page searches native contacts', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->mock(QuickDialContacts::class, function ($mock): void {
        $mock->shouldReceive('checkPermission')
            ->once()
            ->andReturn(true);

        $mock->shouldReceive('search')
            ->once()
            ->with('Jane', 100)
            ->andReturn([
                [
                    'name' => 'Jane Doe',
                    'phone' => '+254700000001',
                    'label' => 'Mobile',
                ],
            ]);
    });

    Livewire::test('quick-dials')
        ->set('customerPhone', 'Jane')
        ->call('searchContacts')
        ->assertSet('contactResults.0.phone', '+254700000001')
        ->assertSee('Jane Doe')
        ->assertSee('Mobile');
});

test('quick dial contact picker starts in search mode instead of dumping all contacts', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('quick-dials')
        ->call('openContactPicker')
        ->assertSet('showContactPicker', true)
        ->assertSee('Type a name or number to search contacts.');
});

test('quick dial page requests contacts permission when missing', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(QuickDialContacts::class, function ($mock): void {
        $mock->shouldReceive('checkPermission')
            ->once()
            ->andReturn(false);

        $mock->shouldReceive('requestPermission')
            ->never();
    });

    Livewire::test('quick-dials')
        ->set('contactSearch', 'Jane')
        ->call('searchContacts')
        ->assertSee('Contacts permission is required');
});

test('quick dial page stores a selected native contact as a local number', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('quick-dials')
        ->call('selectContact', '+254700000001', 'Jane Doe')
        ->assertSet('customerPhone', '0700000001')
        ->assertSet('selectedName', 'Jane Doe')
        ->assertSee('Jane Doe')
        ->assertSee('0700000001');
});

test('quick dial page records a completed award transaction', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => '1 GB Data',
        'category' => 'data',
        'price' => 50,
        'ussd_code' => '*180*5*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    Livewire::test('quick-dials')
        ->set('customerPhone', '0712345678')
        ->call('recordQuickDialResult', $offer->id, true, 'USSD accepted')
        ->assertSet('awardMessage', 'Award sent through 1 GB Data.')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'sender_phone' => '0712345678',
        'amount' => 50,
        'offer_name' => '1 GB Data',
        'offer_type' => 'data',
        'status' => 'completed',
        'status_desc' => 'USSD accepted',
    ]);

    expect(Transaction::query()->first()?->matched_offer)->toMatchArray([
        'source' => 'quick_dial',
        'offer_local_id' => (string) $offer->id,
    ]);
});

test('quick dial page records the phone captured when the ussd request started', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => 'SMS Pack',
        'category' => 'sms',
        'price' => 25,
        'ussd_code' => '*188*PN#',
        'ussd_mode' => 'advanced',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    Livewire::test('quick-dials')
        ->set('customerPhone', '')
        ->call('recordQuickDialResult', $offer->id, true, 'USSD accepted', '+254700000002', 'John Doe')
        ->assertSet('awardMessage', 'Award sent through SMS Pack.')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'sender_phone' => '0700000002',
        'sender_name' => 'John Doe',
        'status' => 'completed',
    ]);
});

test('quick dial page blocks awarding without a valid customer phone', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create(['is_active' => true]);

    $this->actingAs($user);

    Livewire::test('quick-dials')
        ->set('customerPhone', '123')
        ->call('awardOffer', $offer->id)
        ->assertSet('awardError', 'Enter or select a valid customer phone number first.');
});
