<?php

use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('transactions page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('transactions'))
        ->assertOk()
        ->assertSee('Transactions')
        ->assertSee('wire:poll.10s')
        ->assertDontSee('Live sync')
        ->assertDontSee('Track transaction identifiers')
        ->assertDontSee('Refresh');
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
    $response->assertSee('MP123ABC');
    $response->assertSee('254712345678');
    $response->assertSee('John Doe');
    $response->assertSee('Ksh 125');
    $response->assertSee('1 GB Data Bundle');
    $response->assertSee('successful');
    $response->assertSee('USSD');
    $response->assertSee('Transaction completed successfully.');
});

test('failed transactions can be retried from the transactions page', function () {
    $user = User::factory()->create();

    $transaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-RETRY-FAILED',
        'status' => 'failed',
        'status_desc' => 'USSD call failed.',
        'processed_at' => now(),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('transactions')
        ->call('loadTransactions');

    $response->assertSee('Retry');

    $response->call('retryTransaction', $transaction->id);

    $response->assertHasNoErrors();

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'queued',
        'processed_at' => null,
    ]);

    expect($transaction->fresh()->status_desc)->toBe('Retry requested from the Transactions page.');
});

test('pending transactions can be retried from the transactions page', function () {
    $user = User::factory()->create();

    $transaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-RETRY-PENDING',
        'status' => 'pending',
        'status_desc' => 'Waiting for USSD processing.',
        'processed_at' => null,
    ]);

    $this->actingAs($user);

    $response = Livewire::test('transactions')
        ->call('loadTransactions');

    $response->assertSee('Retry');

    $response->call('retryTransaction', $transaction->id);

    $response->assertHasNoErrors();

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'queued',
        'processed_at' => null,
    ]);

    expect($transaction->fresh()->status_desc)->toBe('Retry requested from the Transactions page.');
});
