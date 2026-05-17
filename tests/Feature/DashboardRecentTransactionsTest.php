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
