<?php

use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('transactions page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('transactions'))
        ->assertOk()
        ->assertSee('Transactions')
        ->assertSee('Track transaction identifiers');
});

test('transactions load and display the requested details', function () {
    $user = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-12345678',
        'mpesa_code' => 'MP123ABC',
        'sender_phone' => '254712345678',
        'sender_name' => 'John Doe',
        'amount' => 125,
        'offer_name' => '1 GB Data Bundle',
        'offer_type' => 'data',
        'matched_offer' => 'Auto matched from active offer',
        'occurred_at' => now()->subMinutes(20),
        'status' => 'successful',
        'status_desc' => 'Transaction completed successfully.',
    ]);

    $this->actingAs($user);

    $response = Livewire::test('transactions')
        ->call('loadTransactions');

    $response->assertHasNoErrors();
    $response->assertSet('transactions.0.transaction_id', 'TX-12345678');
    $response->assertSee('TX-12345678');
    $response->assertSee('MP123ABC');
    $response->assertSee('254712345678');
    $response->assertSee('John Doe');
    $response->assertSee('KES 125');
    $response->assertSee('1 GB Data Bundle');
    $response->assertSee('Data');
    $response->assertSee('Auto matched from active offer');
    $response->assertSee('Transaction completed successfully.');
    $response->assertSee('successful');
});
