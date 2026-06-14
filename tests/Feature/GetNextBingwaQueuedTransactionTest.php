<?php

use App\Actions\Autoreach\GetNextBingwaQueuedTransaction;
use App\Jobs\UpdateRemoteTransactionStatusJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\Offer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('fails and queues a remote status update when a queued backend transaction can no longer be processed', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $offer = Offer::factory()->for($user)->create([
        'name' => 'Test 1 day',
        'category' => 'data',
        'price' => 20,
        'is_active' => true,
    ]);

    $transaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-900',
        'offer_id' => $offer->id,
        'mpesa_code' => 'UDH9Z12J9B',
        'sender_phone' => '0719704792',
        'amount' => 20,
        'offer_name' => 'Test 1 day',
        'offer_type' => 'data_bundles',
        'status' => 'queued',
        'status_desc' => 'Pulled from backend job queue.',
    ]);

    $result = app(GetNextBingwaQueuedTransaction::class)->next($user->id);

    expect($result)->toMatchArray([
        'skip' => true,
        'id' => $transaction->id,
    ]);

    $transaction = Transaction::query()->where('transaction_id', 'TX-900')->first();

    expect($transaction)->not->toBeNull();
    expect($transaction?->status)->toBe('failed');
    expect($transaction?->processed_at)->not->toBeNull();
    expect($transaction?->status_desc)->toBe('Subscription expired or deactivated while waiting in queue.');

    Queue::assertPushed(UpdateRemoteTransactionStatusJob::class, function (UpdateRemoteTransactionStatusJob $job): bool {
        return $job->remoteTransactionId === 'TX-900'
            && $job->status === 'failed';
    });
});

it('recovers stuck transactions older than 10 minutes', function (): void {
    $user = User::factory()->create();

    $transaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-901',
        'status' => 'processing',
        'updated_at' => now()->subMinutes(11),
    ]);

    Cache::forget('last_stuck_recovery_run_at');

    app(GetNextBingwaQueuedTransaction::class)->next($user->id, false);

    $transaction->refresh();
    expect($transaction->status)->toBe('queued');
    expect($transaction->status_desc)->toBe('Recovered: previous USSD attempt timed out.');
});

it('recovers stuck transactions using Kotlin tracker when older than 90 seconds', function (): void {
    $user = User::factory()->create();

    $transaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-902',
        'status' => 'processing',
        'updated_at' => now()->subMinutes(3),
    ]);

    $GLOBALS['nativephp_call_mock'] = function (string $function, ?string $payload) use ($transaction): string {
        if ($function === 'GetStuckTransactions') {
            return json_encode([
                'data' => [
                    'transaction_ids' => [$transaction->id],
                    'completed_transactions' => [],
                ],
            ]);
        }

        return '{}';
    };

    Cache::forget('last_stuck_recovery_run_at');

    app(GetNextBingwaQueuedTransaction::class)->next($user->id, false);

    $transaction->refresh();
    expect($transaction->status)->toBe('queued');
    expect($transaction->status_desc)->toBe('Recovered: Kotlin in-flight transaction timed out.');

    unset($GLOBALS['nativephp_call_mock']);
});

it('completes stuck transactions returned by Kotlin outbox rather than resetting to queued', function (): void {
    $user = User::factory()->create();

    $transaction = Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-903',
        'status' => 'processing',
        'updated_at' => now()->subMinutes(3),
    ]);

    $GLOBALS['nativephp_call_mock'] = function (string $function, ?string $payload) use ($transaction): string {
        if ($function === 'GetStuckTransactions') {
            return json_encode([
                'data' => [
                    'transaction_ids' => [],
                    'completed_transactions' => [
                        [
                            'id' => $transaction->id,
                            'success' => true,
                            'message' => 'USSD call succeeded.',
                            'token' => 'callback-token-abc',
                        ],
                    ],
                ],
            ]);
        }

        return '{}';
    };

    Cache::forget('last_stuck_recovery_run_at');

    app(GetNextBingwaQueuedTransaction::class)->next($user->id, false);

    $transaction->refresh();
    expect($transaction->status)->toBe('completed');
    expect($transaction->status_desc)->toBe('USSD call succeeded.');

    $this->assertDatabaseHas('ussd_callback_deliveries', [
        'callback_token' => 'callback-token-abc',
        'transaction_id' => $transaction->id,
        'status' => 'completed',
    ]);

    unset($GLOBALS['nativephp_call_mock']);
});
