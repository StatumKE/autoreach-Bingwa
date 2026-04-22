<?php

use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;

// ─────────────────────────────────────────────────────────────────────────────
// bingwa:complete-transaction
// ─────────────────────────────────────────────────────────────────────────────

it('marks a transaction as completed', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} completed")
        ->assertExitCode(0);

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'completed',
    ]);

    expect($transaction->fresh()->processed_at)->not->toBeNull();
});

it('marks a transaction as failed', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} failed")
        ->assertExitCode(0);

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'failed',
    ]);

    expect($transaction->fresh()->processed_at)->not->toBeNull();
});

it('sets a custom status_desc when --message is provided', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} completed --message='Sambaza confirmed by carrier'")
        ->assertExitCode(0);

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'completed',
        'status_desc' => 'Sambaza confirmed by carrier',
    ]);
});

it('sets a default status_desc when --message is not provided', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} completed")
        ->assertExitCode(0);

    $fresh = $transaction->fresh();
    expect($fresh->status_desc)->not->toBeEmpty();
});

it('returns failure for an invalid status argument', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} unknown")
        ->assertExitCode(1);

    // Status must remain unchanged
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'processing',
    ]);
});

it('exits gracefully when transaction id does not exist', function () {
    $this->artisan('bingwa:complete-transaction 99999 completed')
        ->assertExitCode(0);
});

it('sets the processed_at timestamp on completion', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
        'processed_at' => null,
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} completed")
        ->assertExitCode(0);

    $fresh = $transaction->fresh();
    expect($fresh->processed_at)->not->toBeNull();
    expect($fresh->processed_at->isSameDay(now()))->toBeTrue();
});

it('finalizes a failed transaction once when finalize-once is requested', function () {
    $user = User::factory()->create();
    DeviceSetting::factory()->create([
        'user_id' => $user->id,
        'intelligent_auto_retry' => true,
        'auto_reschedule_rejected' => true,
        'max_attempts' => 4,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
        'retry_count' => 0,
        'processed_at' => null,
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} failed --finalize-once --message='Request rejected by carrier'")
        ->assertExitCode(0);

    $fresh = $transaction->fresh();

    expect($fresh)->not->toBeNull();
    expect($fresh->status)->toBe('failed');
    expect($fresh->retry_count)->toBe(0);
    expect($fresh->processed_at)->not->toBeNull();
    expect($fresh->status_desc)->toBe('Request rejected by carrier');
});
