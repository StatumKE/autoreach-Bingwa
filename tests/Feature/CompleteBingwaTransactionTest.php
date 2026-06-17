<?php

use App\Actions\Autoreach\CompleteBingwaTransaction;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reschedules a transaction for tomorrow when daily limit is reached and offer has retry time', function () {
    $user = User::factory()->create();

    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'retry_time' => 'tomorrow 14:30',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'queued',
        'transaction_id' => 'TXN12345',
        'amount' => 10,
    ]);

    $action = app(CompleteBingwaTransaction::class);

    // Act
    $action->complete($transaction->id, 'failed', 'Recommendation failed. The customer 0700000000 has already been recommended.');

    // Assert original transaction is failed with the actual USSD response message
    $originalTransaction = $transaction->fresh();
    expect($originalTransaction->status)->toBe('failed');
    expect($originalTransaction->status_desc)->toBe('Recommendation failed. The customer 0700000000 has already been recommended.');

    // Assert next_attempt_at is set for tomorrow at 14:30
    $expectedNextAttempt = now()->addDay()->setTimeFromTimeString('14:30');
    expect($originalTransaction->next_attempt_at->toDateTimeString())->toBe($expectedNextAttempt->toDateTimeString());

    // Assert no cloned transaction is created
    $clonedCount = Transaction::where('transaction_id', 'like', 'TXN12345-retry-%')->count();
    expect($clonedCount)->toBe(0);
});

it('does not clone the transaction if the failure message does not match', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'retry_time' => 'tomorrow 14:30',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
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

it('processes auto-renewal reschedules optimally without N+1 issues', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'retry_time' => 'tomorrow 14:30',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'queued',
        'transaction_id' => 'TXN999',
        'amount' => 10,
    ]);

    // Pre-load relations to simulate how GetNextBingwaQueuedTransaction passes the model
    $transaction->load(['user.deviceSetting', 'user.bingwaDeviceRegistration', 'offer']);

    $action = app(CompleteBingwaTransaction::class);

    // Act & measure
    DB::enableQueryLog();

    $action->complete($transaction, 'failed', 'Recommendation failed. The customer 0700000000 has already been recommended.');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Verify it doesn't execute a runaway amount of queries
    expect(count($queries))->toBeLessThan(15);

    // Verify no clones are created
    $clonesCount = Transaction::where('transaction_id', 'like', 'TXN999-retry-%')->count();
    expect($clonesCount)->toBe(0);
});

it('does not schedule a retry if the offer has no retry_time', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'retry_time' => null,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'queued',
        'transaction_id' => 'TXN777',
        'amount' => 10,
    ]);

    $action = app(CompleteBingwaTransaction::class);

    // Act
    $action->complete($transaction->id, 'failed', 'Recommendation failed. The customer 0700000000 has already been recommended.');

    // Assert original transaction is failed and next_attempt_at remains null
    $originalTransaction = $transaction->fresh();
    expect($originalTransaction->status)->toBe('failed');
    expect($originalTransaction->next_attempt_at)->toBeNull();
});

it('treats duplicate completion callbacks as no-ops', function (): void {
    $user = User::factory()->create();

    Plan::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'type' => 'time_unlimited',
        'ussd_counter' => 0,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'queued',
        'transaction_id' => 'TXN-DUPLICATE',
        'amount' => 10,
    ]);

    $action = app(CompleteBingwaTransaction::class);

    expect($action->complete($transaction, 'completed', 'USSD request completed.'))->toBeTrue();

    $firstCompleted = $transaction->fresh();
    $firstPlan = $user->plans()->first();

    expect($firstCompleted?->status)->toBe('completed');
    expect($firstCompleted?->status_desc)->toBe('USSD request completed.');
    expect($firstPlan?->ussd_counter)->toBe(1);

    DB::enableQueryLog();

    expect($action->complete($transaction->fresh(), 'failed', 'late duplicate callback'))->toBeTrue();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $secondCompleted = $transaction->fresh();
    $secondPlan = $user->plans()->first();

    expect($secondCompleted?->status)->toBe('completed');
    expect($secondCompleted?->status_desc)->toBe('USSD request completed.');
    expect($secondPlan?->ussd_counter)->toBe(1);
    expect(count($queries))->toBeLessThan(5);
});

it('records callback delivery tokens exactly once', function (): void {
    $user = User::factory()->create();

    Plan::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'type' => 'time_unlimited',
        'ussd_counter' => 0,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'queued',
        'transaction_id' => 'TXN-TOKEN',
        'amount' => 10,
    ]);

    $action = app(CompleteBingwaTransaction::class);

    expect($action->complete($transaction, 'completed', 'USSD request completed.', 'token-123'))->toBeTrue();
    expect(DB::table('ussd_callback_deliveries')->where('callback_token', 'token-123')->count())->toBe(1);

    expect($action->complete($transaction->fresh(), 'failed', 'late duplicate callback', 'token-123'))->toBeTrue();
    expect(DB::table('ussd_callback_deliveries')->where('callback_token', 'token-123')->count())->toBe(1);
});
