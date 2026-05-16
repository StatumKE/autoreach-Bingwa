<?php

use App\Models\AutoReply;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('transactions page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('transactions'))
        ->assertOk()
        ->assertSee('Transactions')
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

test('transactions page only queries the transaction list once per render', function () {
    $user = User::factory()->create();

    Transaction::factory()
        ->for($user)
        ->count(3)
        ->create([
            'status' => 'completed',
            'status_desc' => 'Transaction completed successfully.',
        ]);

    $this->actingAs($user);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test('transactions')
        ->call('loadTransactions')
        ->assertHasNoErrors();

    $transactionQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(function (string $sql): bool {
            return str_contains(strtolower($sql), 'from "transactions"');
        })
        ->values();

    expect($transactionQueries)->toHaveCount(2);
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

test('retrying a transaction clears any previous auto reply state', function () {
    $user = User::factory()->create();
    $autoReply = AutoReply::factory()->for($user)->create([
        'name' => 'Failed Reply',
        'trigger_condition' => 'failed_transaction',
        'reply_message' => 'Hello there',
        'is_active' => true,
    ]);

    $transaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-RETRY-AUTO-REPLY',
        'status' => 'failed',
        'status_desc' => 'USSD call failed.',
        'processed_at' => now(),
        'auto_reply_id' => $autoReply->id,
        'auto_reply_trigger_condition' => 'failed_transaction',
        'auto_reply_message' => 'Hello there',
        'auto_reply_recipient_phone' => '0712345678',
        'auto_reply_sim_slot' => 'slot_1',
        'auto_reply_status' => 'failed',
        'auto_reply_attempts' => 2,
        'auto_reply_sent_at' => now()->subMinute(),
        'auto_reply_failed_at' => now()->subMinute(),
        'auto_reply_failure_reason' => 'Previous failure',
    ]);

    $this->actingAs($user);

    Livewire::test('transactions')
        ->call('retryTransaction', $transaction->id)
        ->assertHasNoErrors();

    $fresh = $transaction->fresh();

    expect($fresh?->status)->toBe('queued');
    expect($fresh?->auto_reply_status)->toBeNull();
    expect($fresh?->auto_reply_message)->toBeNull();
    expect($fresh?->auto_reply_failure_reason)->toBeNull();
});
