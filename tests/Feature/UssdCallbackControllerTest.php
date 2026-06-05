<?php

use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('processes asynchronous ussd execution callbacks successfully', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->create([
        'user_id' => $user->id,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    $response = $this->postJson('/api/v1/native/ussd/callback', [
        'id' => $transaction->id,
        'success' => true,
        'message' => 'Recommendation submitted successfully.',
    ]);

    $response->assertOk();
    $response->assertJson([
        'success' => true,
    ]);

    $transaction->refresh();
    expect($transaction->status)->toBe('completed');
    expect($transaction->status_desc)->toBe('Recommendation submitted successfully.');
});
