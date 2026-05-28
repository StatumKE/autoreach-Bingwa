<?php

use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\AutoReply;
use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
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
    $offer = Offer::factory()->for($user)->create([
        'name' => '1 GB Data',
        'category' => 'data',
        'price' => 125,
        'ussd_code' => '*180*5*2*PN*1*1#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    $transaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-12345678',
        'offer_id' => $offer->id,
        'mpesa_code' => 'MP123ABC',
        'sender_phone' => '254712345678',
        'sender_name' => 'John Doe',
        'amount' => 125,
        'offer_name' => '1 GB Data Bundle',
        'offer_type' => 'data',
        'matched_offer' => [
            'source' => 'mpesa_match',
            'offer_local_id' => (string) $offer->id,
            'offer_name' => $offer->name,
            'offer_type' => 'data',
        ],
        'occurred_at' => Carbon::parse('2026-04-21 07:00:00', 'UTC'),
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
    $response->assertSee('1 GB Data');
    $response->assertSee('successful');
    $response->assertSee('USSD');
    $response->assertSee('Transaction completed successfully.');
    $response->assertDontSee('#'.$transaction->id);
});

test('transactions page orders rows by newest id first', function () {
    $user = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'sender_name' => 'First Created',
        'offer_name' => 'Older Bundle',
        'amount' => 100,
        'status' => 'completed',
        'status_desc' => 'Older transaction.',
        'occurred_at' => Carbon::parse('2026-04-21 07:00:00', 'UTC'),
    ]);

    Transaction::factory()->for($user)->create([
        'sender_name' => 'Second Created',
        'offer_name' => 'Newer Bundle',
        'amount' => 50,
        'status' => 'failed',
        'status_desc' => 'Newer transaction.',
        'occurred_at' => Carbon::parse('2026-04-20 07:00:00', 'UTC'),
    ]);

    $this->actingAs($user);

    Livewire::test('transactions')
        ->call('loadTransactions')
        ->assertSeeInOrder(['Second Created', 'First Created']);
});

test('transactions rows open a detail modal with the resolved ussd code and matched product', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->for($user)->create([
        'name' => '1 GB Data',
        'category' => 'data',
        'price' => 125,
        'ussd_code' => '*180*5*2*PN*1*1#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    $transaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-DETAILS-1',
        'offer_id' => $offer->id,
        'mpesa_code' => 'MP123ABC',
        'sender_phone' => '0712345678',
        'sender_name' => 'John Doe',
        'amount' => 125,
        'offer_name' => '1 GB Data Bundle',
        'offer_type' => 'data',
        'matched_offer' => [
            'source' => 'mpesa_match',
            'offer_local_id' => (string) $offer->id,
            'offer_name' => $offer->name,
            'offer_type' => 'data',
        ],
        'balance' => [
            'airtime' => 10,
            'bundle' => '1 GB',
        ],
        'occurred_at' => Carbon::parse('2026-04-21 07:00:00', 'UTC'),
        'status' => 'completed',
        'status_desc' => 'Transaction completed successfully.',
        'processed_at' => Carbon::parse('2026-04-21 07:15:00', 'UTC'),
        'retry_count' => 1,
        'auto_reply_status' => 'sent',
        'auto_reply_attempts' => 2,
    ]);

    $this->actingAs($user);

    Livewire::test('transactions')
        ->call('loadTransactions')
        ->call('openTransactionDetails', $transaction->id)
        ->assertSet('showTransactionDetails', true)
        ->assertSet('selectedTransactionId', $transaction->id)
        ->assertSee('Transaction #'.$transaction->id)
        ->assertSee('Matched App Product')
        ->assertSee('1 GB Data')
        ->assertSee('*180*5*2*0712345678*1*1#')
        ->assertSee('Copy')
        ->assertSee('Transaction completed successfully.')
        ->assertDontSee('Matched Payload')
        ->assertDontSee('Local Row Data');
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

    expect($transactionQueries)->toHaveCount(4);
});

test('failed transactions can be retried from the transactions page', function () {
    Bus::fake();

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

    Bus::assertDispatched(ProcessBingwaQueuedTransactionsJob::class, 1);
});

test('pending transactions can be retried from the transactions page', function () {
    Bus::fake();

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

    Bus::assertDispatched(ProcessBingwaQueuedTransactionsJob::class, 1);
});

test('retrying a transaction clears any previous auto reply state', function () {
    Bus::fake();

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

    Bus::assertDispatched(ProcessBingwaQueuedTransactionsJob::class, 1);
});

test('selected transactions can be retried in bulk from the transactions page', function () {
    Bus::fake();

    $user = User::factory()->create();

    $firstTransaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-BULK-1',
        'status' => 'failed',
        'status_desc' => 'USSD call failed.',
        'processed_at' => now(),
    ]);

    $secondTransaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-BULK-2',
        'status' => 'pending',
        'status_desc' => 'Waiting for USSD processing.',
        'processed_at' => null,
    ]);

    $this->actingAs($user);

    $response = Livewire::test('transactions')
        ->call('loadTransactions')
        ->set('selectedIds', [$firstTransaction->id, $secondTransaction->id]);

    $response->call('retrySelectedTransactions');

    $response->assertHasNoErrors();
    $response->assertSet('selectedIds', []);

    $this->assertDatabaseHas('transactions', [
        'id' => $firstTransaction->id,
        'status' => 'queued',
        'processed_at' => null,
    ]);

    $this->assertDatabaseHas('transactions', [
        'id' => $secondTransaction->id,
        'status' => 'queued',
        'processed_at' => null,
    ]);

    expect($firstTransaction->fresh()->status_desc)->toBe('Retry requested from the Transactions page.');
    expect($secondTransaction->fresh()->status_desc)->toBe('Retry requested from the Transactions page.');

    Bus::assertDispatched(ProcessBingwaQueuedTransactionsJob::class, 1);
});

test('transactions can be toggled using select all page action', function () {
    $user = User::factory()->create();

    $retryable1 = Transaction::factory()->for($user)->create([
        'status' => 'failed',
    ]);
    $retryable2 = Transaction::factory()->for($user)->create([
        'status' => 'pending',
    ]);
    $nonRetryable = Transaction::factory()->for($user)->create([
        'status' => 'completed',
    ]);

    $this->actingAs($user);

    Livewire::test('transactions')
        ->call('loadTransactions')
        // Try selecting all when currently not selected
        ->call('toggleSelectAllPage', false)
        // Assert it only contains retryable IDs.
        ->assertSet('selectedIds', [$retryable2->id, $retryable1->id])
        // Now toggle selecting all off (currentlySelected = true)
        ->call('toggleSelectAllPage', true)
        ->assertSet('selectedIds', []);
});

test('transactions can be deleted from the transactions page', function () {
    $user = User::factory()->create();
    $txs = Transaction::factory()->for($user)->count(3)->create([
        'status' => 'failed',
    ]);

    $this->actingAs($user);

    expect(Transaction::where('user_id', $user->id)->count())->toBe(3);

    Livewire::test('transactions')
        ->call('loadTransactions')
        ->set('selectedIds', [$txs[0]->id, $txs[1]->id])
        ->call('deleteSelectedTransactions')
        ->assertHasNoErrors();

    expect(Transaction::where('user_id', $user->id)->count())->toBe(1);
    expect(Transaction::where('id', $txs[2]->id)->exists())->toBeTrue();
});
