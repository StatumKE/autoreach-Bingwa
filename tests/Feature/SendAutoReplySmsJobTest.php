<?php

use App\Actions\Autoreach\SendAutoReplySms;
use App\Jobs\SendAutoReplySmsJob;
use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;
use Mockery\MockInterface;

it('sends a queued auto reply sms and marks the transaction as sent', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->create([
        'user_id' => $user->id,
        'sms_auto_reply_sim' => 'slot_2',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed',
        'auto_reply_id' => null,
        'auto_reply_trigger_condition' => 'successful_transaction',
        'auto_reply_message' => 'Hello Jane, thanks for buying from Bingwa.',
        'auto_reply_recipient_phone' => '0712345678',
        'auto_reply_status' => 'queued',
        'auto_reply_attempts' => 0,
        'auto_reply_sent_at' => null,
        'auto_reply_failed_at' => null,
        'auto_reply_failure_reason' => null,
    ]);

    $this->mock(SendAutoReplySms::class, function (MockInterface $mock): void {
        $mock->shouldReceive('send')
            ->once()
            ->with('0712345678', 'Hello Jane, thanks for buying from Bingwa.', 1)
            ->andReturn([
                'success' => true,
                'message' => 'SMS submitted successfully.',
                'retryable' => false,
                'raw_response' => '{"success":true}',
            ]);
    });

    (new SendAutoReplySmsJob($transaction->id))
        ->handle(app(SendAutoReplySms::class));

    $fresh = $transaction->fresh();

    expect($fresh?->auto_reply_status)->toBe('sent');
    expect($fresh?->auto_reply_sent_at)->not->toBeNull();
    expect($fresh?->auto_reply_failed_at)->toBeNull();
    expect($fresh?->auto_reply_failure_reason)->toBeNull();
    expect($fresh?->auto_reply_attempts)->toBe(1);
    expect($fresh?->auto_reply_sim_slot)->toBe('slot_2');
});

it('records a non-retryable auto reply failure without throwing', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->create([
        'user_id' => $user->id,
        'sms_auto_reply_sim' => 'slot_1',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed',
        'auto_reply_id' => null,
        'auto_reply_trigger_condition' => 'successful_transaction',
        'auto_reply_message' => 'Hello Jane, thanks for buying from Bingwa.',
        'auto_reply_recipient_phone' => '0712345678',
        'auto_reply_status' => 'queued',
        'auto_reply_attempts' => 0,
        'auto_reply_sent_at' => null,
        'auto_reply_failed_at' => null,
        'auto_reply_failure_reason' => null,
    ]);

    $this->mock(SendAutoReplySms::class, function (MockInterface $mock): void {
        $mock->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'SEND_SMS permission not granted',
                'retryable' => false,
                'raw_response' => null,
            ]);
    });

    (new SendAutoReplySmsJob($transaction->id))
        ->handle(app(SendAutoReplySms::class));

    $fresh = $transaction->fresh();

    expect($fresh?->auto_reply_status)->toBe('failed');
    expect($fresh?->auto_reply_sent_at)->toBeNull();
    expect($fresh?->auto_reply_failed_at)->not->toBeNull();
    expect($fresh?->auto_reply_failure_reason)->toBe('SEND_SMS permission not granted');
});

it('throws for retryable auto reply failures so the queue can try again', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->create([
        'user_id' => $user->id,
        'sms_auto_reply_sim' => 'slot_1',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'completed',
        'auto_reply_id' => null,
        'auto_reply_trigger_condition' => 'successful_transaction',
        'auto_reply_message' => 'Hello Jane, thanks for buying from Bingwa.',
        'auto_reply_recipient_phone' => '0712345678',
        'auto_reply_status' => 'queued',
        'auto_reply_attempts' => 0,
        'auto_reply_sent_at' => null,
        'auto_reply_failed_at' => null,
        'auto_reply_failure_reason' => null,
    ]);

    $this->mock(SendAutoReplySms::class, function (MockInterface $mock): void {
        $mock->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'message' => 'Temporary bridge error',
                'retryable' => true,
                'raw_response' => null,
            ]);
    });

    $job = new SendAutoReplySmsJob($transaction->id);

    expect(fn () => $job->handle(app(SendAutoReplySms::class)))
        ->toThrow(RuntimeException::class, 'Temporary bridge error');

    $fresh = $transaction->fresh();

    expect($fresh?->auto_reply_status)->toBe('failed');
    expect($fresh?->auto_reply_failed_at)->not->toBeNull();
    expect($fresh?->auto_reply_failure_reason)->toBe('Temporary bridge error');
});
