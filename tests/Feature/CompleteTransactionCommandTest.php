<?php

use App\Jobs\SendAutoReplySmsJob;
use App\Jobs\UpdateRemoteTransactionStatusJob;
use App\Models\AutoReply;
use App\Models\BingwaDeviceRegistration;
use App\Models\DeviceSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

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

it('queues an auto reply sms job after a successful transaction when an active rule exists', function () {
    Queue::fake();

    $user = User::factory()->create();
    DeviceSetting::factory()->for($user)->create([
        'sms_auto_reply_sim' => 'slot_2',
    ]);

    AutoReply::factory()->for($user)->create([
        'name' => 'Successful Reply',
        'trigger_condition' => 'successful_transaction',
        'reply_message' => 'Hello <firstName>, thanks for buying from Bingwa.',
        'is_active' => true,
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
        'sender_phone' => '254712345678',
        'sender_name' => 'Jane Doe',
        'amount' => 50,
        'offer_name' => '1 GB Data',
        'status_desc' => 'Transaction completed successfully.',
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} completed --finalize-once")
        ->assertExitCode(0);

    Queue::assertPushed(SendAutoReplySmsJob::class, function (SendAutoReplySmsJob $job) use ($transaction): bool {
        return $job->transactionId === $transaction->id;
    });

    $fresh = $transaction->fresh();

    expect($fresh?->auto_reply_status)->toBe('queued');
    expect($fresh?->auto_reply_trigger_condition)->toBe('successful_transaction');
    expect($fresh?->auto_reply_message)->toBe('Hello Jane, thanks for buying from Bingwa.');
    expect($fresh?->auto_reply_recipient_phone)->toBe('0712345678');
});

it('marks auto reply as skipped when no active rule matches', function () {
    Queue::fake();

    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
        'sender_phone' => '254712345678',
        'sender_name' => 'Jane Doe',
        'amount' => 50,
        'offer_name' => '1 GB Data',
        'status_desc' => 'Transaction completed successfully.',
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} completed --finalize-once")
        ->assertExitCode(0);

    Queue::assertNothingPushed();

    $fresh = $transaction->fresh();

    expect($fresh?->auto_reply_status)->toBe('skipped');
    expect($fresh?->auto_reply_trigger_condition)->toBe('successful_transaction');
    expect($fresh?->auto_reply_message)->toBeNull();
    expect($fresh?->auto_reply_failure_reason)->not->toBeNull();
});

it('sets a base64 encoded status_desc containing spaces and newlines', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);
    $message = "Invalid choice. Try again.\nPlease enter your Till Number:";
    $encodedMessage = base64_encode($message);

    $this->artisan("bingwa:complete-transaction {$transaction->id} failed --finalize-once --message-base64={$encodedMessage}")
        ->assertExitCode(0);

    $fresh = $transaction->fresh();

    expect($fresh->status)->toBe('failed');
    expect($fresh->status_desc)->toBe($message);
    expect($fresh->processed_at)->not->toBeNull();
});

it('supports option-only invocation for native runtime calls', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);
    $message = 'Network returned a general failure';
    $encodedMessage = base64_encode($message);

    $this->artisan("bingwa:complete-transaction --transaction-id={$transaction->id} --result=failed --finalize-once --message-base64={$encodedMessage}")
        ->assertExitCode(0);

    $fresh = $transaction->fresh();

    expect($fresh->status)->toBe('failed');
    expect($fresh->status_desc)->toBe($message);
    expect($fresh->processed_at)->not->toBeNull();
});

it('returns failure for an invalid base64 status_desc', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'status' => 'processing',
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} failed --message-base64=not-base64!")
        ->assertExitCode(1);

    $fresh = $transaction->fresh();

    expect($fresh->status)->toBe('processing');
    expect($fresh->processed_at)->toBeNull();
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

it('does not dispatch a backend status update for quick dial transactions', function () {
    Queue::fake();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => 'QD-20260516120000-ABCDE',
        'status' => 'processing',
        'matched_offer' => [
            'source' => 'quick_dial',
        ],
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} completed --finalize-once --message='USSD accepted'")
        ->assertExitCode(0);

    $fresh = $transaction->fresh();

    expect($fresh?->status)->toBe('completed');
    expect($fresh?->status_desc)->toBe('USSD accepted');

    Queue::assertNotPushed(UpdateRemoteTransactionStatusJob::class);
});

it('does not dispatch a backend status update for auto renewal transactions', function () {
    Queue::fake();

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'transaction_id' => 'AR-1-20260516120000-ABCDE',
        'status' => 'processing',
        'matched_offer' => [
            'source' => 'auto_renewal',
        ],
    ]);

    $this->artisan("bingwa:complete-transaction {$transaction->id} completed --finalize-once --message='USSD accepted'")
        ->assertExitCode(0);

    expect($transaction->fresh()?->status)->toBe('completed');

    Queue::assertNotPushed(UpdateRemoteTransactionStatusJob::class);
});
