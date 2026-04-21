<?php

use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;

it('requeues all processing transactions when forced', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'ussd_code' => '*180*5*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    $first = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'processing',
        'status_desc' => 'USSD call in progress.',
    ]);

    $second = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'processing',
        'status_desc' => 'USSD call in progress.',
    ]);

    $this->artisan('bingwa:requeue-stuck-transactions --all')
        ->expectsOutputToContain('Requeued 2 transaction(s).')
        ->assertExitCode(0);

    $this->assertDatabaseHas('transactions', [
        'id' => $first->id,
        'status' => 'queued',
    ]);

    $this->assertDatabaseHas('transactions', [
        'id' => $second->id,
        'status' => 'queued',
    ]);
});

it('only requeues stale processing transactions by default', function () {
    $user = User::factory()->create();
    $offer = Offer::factory()->create([
        'user_id' => $user->id,
        'ussd_code' => '*180*5*PN#',
        'ussd_mode' => 'express',
        'is_active' => true,
    ]);

    $stale = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'processing',
        'status_desc' => 'USSD call in progress.',
    ]);

    $fresh = Transaction::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => 'processing',
        'status_desc' => 'USSD call in progress.',
    ]);

    Transaction::query()
        ->whereKey($stale->id)
        ->update(['updated_at' => now()->subMinutes(3)]);

    Transaction::query()
        ->whereKey($fresh->id)
        ->update(['updated_at' => now()->subSeconds(30)]);

    $this->artisan('bingwa:requeue-stuck-transactions')
        ->expectsOutputToContain('Requeued 1 transaction(s).')
        ->assertExitCode(0);

    $this->assertDatabaseHas('transactions', [
        'id' => $stale->id,
        'status' => 'queued',
    ]);

    $this->assertDatabaseHas('transactions', [
        'id' => $fresh->id,
        'status' => 'processing',
    ]);
});
