<?php

use App\Actions\Autoreach\CompleteBingwaTransaction;
use App\Actions\Autoreach\ExecuteBingwaUssd;
use App\Actions\Autoreach\GetNextBingwaQueuedTransaction;
use App\Jobs\ProcessBingwaQueuedTransactionsJob;
use App\Models\DeviceSetting;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use Mockery\MockInterface;

it('claims queued transactions, executes ussd, and finalizes them', function (): void {
    $user = User::factory()->create();

    Offer::factory()->create([
        'user_id' => $user->id,
        'ussd_code' => '*180*5*7*PN#',
        'ussd_mode' => 'express',
        'price' => 15,
        'is_active' => true,
    ]);

    Plan::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'type' => 'time_unlimited',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => 'TX-123',
        'sender_phone' => '0712345678',
        'amount' => 15,
        'offer_name' => 'Data',
        'offer_type' => 'data_bundles',
        'status' => 'queued',
        'status_desc' => 'Pulled from backend job queue.',
    ]);

    $this->mock(GetNextBingwaQueuedTransaction::class, function (MockInterface $mock) use ($transaction): void {
        $mock->shouldReceive('next')
            ->twice()
            ->andReturn(
                [
                    'id' => $transaction->id,
                    'backend_transaction_id' => 'TX-123',
                    'code' => '*180*5*7*0712345678#',
                    'mode' => 'express',
                    'sim_slot' => 0,
                    'timeout' => 30,
                    'backend_url' => 'https://backend.example.com',
                    'device_token' => 'raw-device-token',
                ],
                null,
            );
    });

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->withArgs(function (array $payload, ?string $flowId): bool {
                return ($payload['backend_transaction_id'] ?? null) === 'TX-123'
                    && ($payload['code'] ?? null) === '*180*5*7*0712345678#'
                    && ($payload['mode'] ?? null) === 'express'
                    && ((int) ($payload['sim_slot'] ?? -1)) === 0
                    && is_string($flowId) && $flowId !== '';
            })
            ->andReturn([
                'success' => true,
                'message' => 'USSD request completed.',
                'raw_response' => '{"data":{"success":true,"message":"USSD request completed."}}',
            ]);
    });

    (new ProcessBingwaQueuedTransactionsJob($user->id))
        ->handle(app(CompleteBingwaTransaction::class), app(ExecuteBingwaUssd::class), app(GetNextBingwaQueuedTransaction::class));

    $fresh = $transaction->fresh();
    $plan = $user->plans()->first();

    expect($fresh?->status)->toBe('completed');
    expect($fresh?->processed_at)->not->toBeNull();
    expect($fresh?->status_desc)->toBe('USSD request completed.');
    expect($plan?->ussd_counter)->toBe(1);
});

it('does nothing when there are no queued transactions', function (): void {
    $user = User::factory()->create();

    $this->mock(GetNextBingwaQueuedTransaction::class, function (MockInterface $mock): void {
        $mock->shouldReceive('next')
            ->once()
            ->andReturn(null);
    });

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('execute');
    });

    (new ProcessBingwaQueuedTransactionsJob($user->id))
        ->handle(app(CompleteBingwaTransaction::class), app(ExecuteBingwaUssd::class), app(GetNextBingwaQueuedTransaction::class));

    expect(Transaction::query()->count())->toBe(0);
});

it('treats carrier submitted successfully text as completed even when bridge success is false', function (): void {
    $user = User::factory()->create();

    Plan::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'type' => 'time_unlimited',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => 'TX-SUCCESS-TEXT',
        'sender_phone' => '0721553678',
        'amount' => 19,
        'status' => 'queued',
        'status_desc' => 'Pulled from backend job queue.',
    ]);

    $this->mock(GetNextBingwaQueuedTransaction::class, function (MockInterface $mock) use ($transaction): void {
        $mock->shouldReceive('next')
            ->twice()
            ->andReturn([
                'id' => $transaction->id,
                'backend_transaction_id' => 'TX-SUCCESS-TEXT',
                'code' => '*180*5*2*0721553678*5*1#',
                'mode' => 'express',
                'sim_slot' => 0,
                'timeout' => 30,
            ], null);
    });

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Recommendation for 0721553678 submitted successfully. Keep selling!! Be a Bingwa Sokoni Champion.',
            ]);
    });

    (new ProcessBingwaQueuedTransactionsJob($user->id))
        ->handle(app(CompleteBingwaTransaction::class), app(ExecuteBingwaUssd::class), app(GetNextBingwaQueuedTransaction::class));

    $fresh = $transaction->fresh();

    expect($fresh?->status)->toBe('completed');
    expect($fresh?->retry_count)->toBe(0);
    expect($fresh?->processed_at)->not->toBeNull();
    expect($fresh?->status_desc)->toBe('Recommendation for 0721553678 submitted successfully. Keep selling!! Be a Bingwa Sokoni Champion.');
});

it('honors auto retry settings for failed native processor attempts', function (): void {
    $user = User::factory()->create();

    DeviceSetting::factory()->create([
        'user_id' => $user->id,
        'intelligent_auto_retry' => true,
        'auto_reschedule_rejected' => true,
        'max_attempts' => 4,
    ]);

    Plan::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'type' => 'time_unlimited',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => 'TX-NO-AUTO-RETRY',
        'sender_phone' => '0721553678',
        'amount' => 19,
        'status' => 'queued',
        'retry_count' => 0,
        'status_desc' => 'Pulled from backend job queue.',
    ]);

    $this->mock(GetNextBingwaQueuedTransaction::class, function (MockInterface $mock) use ($transaction): void {
        $mock->shouldReceive('next')
            ->twice()
            ->andReturn([
                'id' => $transaction->id,
                'backend_transaction_id' => 'TX-NO-AUTO-RETRY',
                'code' => '*180*5*2*0721553678*5*1#',
                'mode' => 'express',
                'sim_slot' => 0,
                'timeout' => 30,
            ], null);
    });

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Network returned a general failure',
            ]);
    });

    (new ProcessBingwaQueuedTransactionsJob($user->id))
        ->handle(app(CompleteBingwaTransaction::class), app(ExecuteBingwaUssd::class), app(GetNextBingwaQueuedTransaction::class));

    $fresh = $transaction->fresh();

    expect($fresh?->status)->toBe('queued');
    expect($fresh?->retry_count)->toBe(1);
    expect($fresh?->processed_at)->toBeNull();
    expect($fresh?->status_desc)->toContain('Auto-retry attempt');
});

it('stops processing immediately when transaction processing is paused', function (): void {
    $user = User::factory()->create();

    DeviceSetting::factory()->create([
        'user_id' => $user->id,
        'transaction_processing_enabled' => false,
    ]);

    Plan::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'type' => 'time_unlimited',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => 'TX-PAUSED',
        'sender_phone' => '0712345678',
        'amount' => 15,
        'offer_name' => 'Data',
        'offer_type' => 'data_bundles',
        'status' => 'queued',
        'status_desc' => 'Pulled from backend job queue.',
    ]);

    $this->mock(GetNextBingwaQueuedTransaction::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('next');
    });

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('execute');
    });

    (new ProcessBingwaQueuedTransactionsJob($user->id))
        ->handle(app(CompleteBingwaTransaction::class), app(ExecuteBingwaUssd::class), app(GetNextBingwaQueuedTransaction::class));

    expect($transaction->fresh()?->status)->toBe('queued');
});

it('aborts the processor loop on lock contention/modem busy', function (): void {
    $user = User::factory()->create();

    Plan::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'type' => 'time_unlimited',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => 'TX-LOCK-ABORT',
        'status' => 'queued',
    ]);

    $this->mock(GetNextBingwaQueuedTransaction::class, function (MockInterface $mock) use ($transaction): void {
        $mock->shouldReceive('next')
            ->once()
            ->andReturn([
                'id' => $transaction->id,
                'backend_transaction_id' => 'TX-LOCK-ABORT',
                'code' => '*180#',
                'mode' => 'express',
                'sim_slot' => 0,
                'timeout' => 30,
            ]);
    });

    $this->mock(ExecuteBingwaUssd::class, function (MockInterface $mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Another USSD session is already in progress',
            ]);
    });

    (new ProcessBingwaQueuedTransactionsJob($user->id))
        ->handle(app(CompleteBingwaTransaction::class), app(ExecuteBingwaUssd::class), app(GetNextBingwaQueuedTransaction::class));

    $fresh = $transaction->fresh();
    expect($fresh?->status)->toBe('queued');
    expect($fresh?->retry_count)->toBe(0);
});
