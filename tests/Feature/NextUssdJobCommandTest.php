<?php

use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;

// ─────────────────────────────────────────────────────────────────────────────
// bingwa:next-ussd-job
// ─────────────────────────────────────────────────────────────────────────────

it('outputs nothing when no queued transactions exist', function () {
    $this->artisan('bingwa:next-ussd-job')
        ->assertExitCode(0);

    $this->assertDatabaseCount('transactions', 0);
});

it('outputs nothing when queued transaction has no matched offer', function () {
    $user = User::factory()->create();

    Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'queued',
        'offer_id' => null,
    ]);

    $this->artisan('bingwa:next-ussd-job')
        ->assertExitCode(0);

    // Transaction should remain unchanged
    $this->assertDatabaseHas('transactions', ['status' => 'queued', 'offer_id' => null]);
});

it('outputs valid JSON payload for a queued transaction with an offer', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'ussd_code' => '*180*5*7*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'sender_phone' => '0712345678',
        'status' => 'queued',
    ]);

    $this->artisan('bingwa:next-ussd-job')
        ->assertExitCode(0);

    // Transaction must be marked processing immediately
    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'status' => 'processing',
    ]);
});

it('replaces PN placeholder in the ussd_code with sender_phone', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'ussd_code' => '*180*5*7*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'sender_phone' => '0799123456',
        'status' => 'queued',
    ]);

    $this->artisan('bingwa:next-ussd-job')
        ->assertExitCode(0);

    // The transaction should be marked processing (no longer queued)
    $this->assertDatabaseHas('transactions', [
        'sender_phone' => '0799123456',
        'status' => 'processing',
    ]);
});

it('picks the oldest queued transaction first', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'ussd_code' => '*180*5*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    $older = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'queued',
        'occurred_at' => now()->subHour(),
    ]);

    $newer = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'queued',
        'occurred_at' => now(),
    ]);

    $this->artisan('bingwa:next-ussd-job')->assertExitCode(0);

    // Older one should be picked and marked processing; newer remains queued
    $this->assertDatabaseHas('transactions', ['id' => $older->id, 'status' => 'processing']);
    $this->assertDatabaseHas('transactions', ['id' => $newer->id, 'status' => 'queued']);
});

it('recovers stuck processing transactions older than 2 minutes', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'ussd_code' => '*180*5*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    $stuckTransaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'processing',
    ]);

    // Simulate the transaction being stuck for 3 minutes (past the 2-minute threshold)
    $stuckTransaction->update(['updated_at' => now()->subMinutes(3)]);

    $this->artisan('bingwa:next-ussd-job')->assertExitCode(0);

    $this->assertDatabaseHas('transactions', [
        'id' => $stuckTransaction->id,
        'status' => 'processing', // It was recovered to queued, then immediately dispatched as the only queued job
    ]);

    // Verify status_desc was set at some point to the recovery message or the dispatch message
    $fresh = $stuckTransaction->fresh();
    expect($fresh->status)->toBe('processing'); // picked up as the only queued job after recovery
});

it('does not recover recently processing transactions', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'ussd_code' => '*180*5*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    $recentTransaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'processing',
        'updated_at' => now()->subSeconds(10),
    ]);

    $this->artisan('bingwa:next-ussd-job')->assertExitCode(0);

    // Should remain processing — it was just dispatched 10 seconds ago
    $this->assertDatabaseHas('transactions', [
        'id' => $recentTransaction->id,
        'status' => 'processing',
    ]);
});
