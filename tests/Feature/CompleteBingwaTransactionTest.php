<?php

use App\Actions\Autoreach\CompleteBingwaTransaction;
use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('clones a transaction and schedules it for tomorrow when daily limit is reached', function () {
    $user = User::factory()->create();

    // Create device settings that specify a retry time
    DeviceSetting::factory()->create([
        'user_id' => $user->id,
        'retry_tomorrow_at' => '14:30',
        'auto_reschedule_rejected' => true,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'queued',
        'transaction_id' => 'TXN12345',
        'amount' => 10,
    ]);

    $action = app(CompleteBingwaTransaction::class);

    // Act
    $action->complete($transaction->id, 'failed', 'Recommendation failed. The customer 0700000000 has already been recommended.');

    // Assert original transaction is failed with specific message
    $originalTransaction = $transaction->fresh();
    expect($originalTransaction->status)->toBe('failed');
    expect($originalTransaction->status_desc)->toBe('Failed (Daily limit reached). Marked for auto-renewal tomorrow at 14:30.');

    // Assert cloned transaction is created and queued for tomorrow at 14:30
    $clonedTransaction = Transaction::where('transaction_id', 'like', 'TXN12345-retry-%')->first();
    expect($clonedTransaction)->not->toBeNull();
    expect($clonedTransaction->status)->toBe('queued');

    $expectedNextAttempt = now()->addDay()->setTimeFromTimeString('14:30');
    expect($clonedTransaction->next_attempt_at->toDateTimeString())->toBe($expectedNextAttempt->toDateTimeString());
});

it('does not clone the transaction if the failure message does not match', function () {
    $user = User::factory()->create();
    DeviceSetting::factory()->create([
        'user_id' => $user->id,
        'retry_tomorrow_at' => '14:30',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'queued',
        'transaction_id' => 'TXN54321',
    ]);

    $action = app(CompleteBingwaTransaction::class);

    // Act
    $action->complete($transaction->id, 'failed', 'Insufficient balance');

    // Assert original transaction is failed normally
    $originalTransaction = $transaction->fresh();
    expect($originalTransaction->status)->toBe('failed');
    expect($originalTransaction->status_desc)->toBe('Insufficient balance');

    // Assert no clone was created
    $clonesCount = Transaction::where('transaction_id', 'like', 'TXN54321-retry-%')->count();
    expect($clonesCount)->toBe(0);
});

it('processes auto-renewal clones optimally without N+1 issues', function () {
    $user = User::factory()->create();
    DeviceSetting::factory()->create([
        'user_id' => $user->id,
        'retry_tomorrow_at' => '14:30',
        'auto_reschedule_rejected' => true,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'queued',
        'transaction_id' => 'TXN999',
        'amount' => 10,
    ]);

    // Pre-load relations to simulate how GetNextBingwaQueuedTransaction passes the model
    $transaction->load(['user.deviceSetting', 'user.bingwaDeviceRegistration']);

    $action = app(CompleteBingwaTransaction::class);

    // We expect a fixed, flat number of queries when completing the transaction.
    // 1 DB Transaction Begin, 1 Update to original, 1 Insert for clone, 1 DB Transaction Commit.
    // Plus maybe a couple for auto-replies or plan usage.

    // Act & measure
    DB::enableQueryLog();

    $action->complete($transaction, 'failed', 'Recommendation failed. The customer 0700000000 has already been recommended.');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Verify it doesn't execute a runaway amount of queries
    expect(count($queries))->toBeLessThan(15);

    // Verify the clone still works exactly as expected
    $clonesCount = Transaction::where('transaction_id', 'like', 'TXN999-retry-%')->count();
    expect($clonesCount)->toBe(1);
});
