<?php

use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('quick dial page can be rendered', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('quick-dials'))
        ->assertOk()
        ->assertSee('Quick Dial')
        ->assertSee('Customer information')
        ->assertSee('Available offers');
});

test('quick dial page blocks opening confirmation without a valid customer phone', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create(['is_active' => true]);

    $this->actingAs($user);

    Livewire::test('quick-dials')
        ->set('customerPhone', '123')
        ->call('prepareAwardOffer', $offer->id)
        ->assertSet('selectedOfferId', null)
        ->assertSet('awardError', 'Enter or select a valid customer phone number first.');
});

test('quick dial page opens a confirmation modal for a valid award', function () {
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
        ->assertSet('selectedOfferId', $offer->id)
        ->assertSee('Confirm Award')
        ->assertSee('Award Now');
});

test('quick dial page queues an award transaction for the normal processor', function () {
    Queue::fake();

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
        ->call('awardOffer', $offer->id)
        ->assertSet('awardMessage', 'Award queued through 1 GB Data.')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'sender_phone' => '0712345678',
        'amount' => 50,
        'offer_name' => '1 GB Data',
        'offer_type' => 'data',
        'status' => 'queued',
        'status_desc' => 'Quick Dial award queued for processing.',
    ]);

    expect(Transaction::query()->first()?->matched_offer)->toMatchArray([
        'source' => 'quick_dial',
        'offer_local_id' => (string) $offer->id,
    ]);

    Queue::assertPushed(ProcessBingwaQueuedTransactionsJob::class, function (ProcessBingwaQueuedTransactionsJob $job) use ($user): bool {
        return $job->userId === $user->id;
    });
});

test('quick dial page normalizes the phone before queueing the award transaction', function () {
    Queue::fake();

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
        ->set('customerPhone', '+254700000002')
        ->call('awardOffer', $offer->id)
        ->assertSet('awardMessage', 'Award queued through SMS Pack.')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'sender_phone' => '0700000002',
        'sender_name' => null,
        'status' => 'queued',
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
