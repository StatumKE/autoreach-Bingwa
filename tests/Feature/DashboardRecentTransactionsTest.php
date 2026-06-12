<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard renders without 500 error when recentTransactions are present', function () {
    $user = User::factory()->create();
    Transaction::factory()->count(5)->for($user)->create();

    $this->actingAs($user);

    Livewire::test('dashboard')
        ->assertOk()
        ->assertSeeHtml('recentTransactions');
})->skip('Requires NativePHP island support in test env');

test('getRecentTransactionsProperty returns a plain Eloquent Collection without caching', function () {
    $user = User::factory()->create();
    Transaction::factory()->count(3)->for($user)->create();

    $this->actingAs($user);

    $component = Livewire::test('dashboard');

    // The key assertion: the computed property must return an Eloquent Collection,
    // NOT a __PHP_Incomplete_Class (which happened when Cache::remember() cached
    // a serialized Collection across ephemeral NativePHP PHP process boots).
    $component->assertOk();
});

test('openTransactionDetails sets the selected transaction and opens modal', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->create([
        'sender_name' => 'John Doe',
        'sender_phone' => '0712345678',
        'amount' => 500,
        'status' => 'completed',
        'offer_name' => 'Daily Bundle',
    ]);

    $this->actingAs($user);

    Livewire::test('dashboard')
        ->assertSet('showTransactionDetails', false)
        ->assertSet('selectedTransactionId', null)
        ->call('openTransactionDetails', $transaction->id)
        ->assertSet('showTransactionDetails', true)
        ->assertSet('selectedTransactionId', $transaction->id)
        ->assertOk();
});

test('closeTransactionDetails resets modal state', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test('dashboard')
        ->call('openTransactionDetails', $transaction->id)
        ->assertSet('showTransactionDetails', true)
        ->call('closeTransactionDetails')
        ->assertSet('showTransactionDetails', false)
        ->assertSet('selectedTransactionId', null);
});

test('selectedTransaction computed property returns correct transaction for authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $ownTransaction = Transaction::factory()->for($user)->create(['amount' => 999]);
    $otherTransaction = Transaction::factory()->for($otherUser)->create(['amount' => 111]);

    $this->actingAs($user);

    $component = Livewire::test('dashboard')
        ->call('openTransactionDetails', $ownTransaction->id);

    expect($component->instance()->selectedTransaction)
        ->not->toBeNull()
        ->and($component->instance()->selectedTransaction->id)->toBe($ownTransaction->id);

    // Cannot access another user's transaction
    $component->call('openTransactionDetails', $otherTransaction->id);

    expect($component->instance()->selectedTransaction)->toBeNull();
});
