<?php

use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the sms history page', function () {
    $this->get(route('sms'))
        ->assertRedirect(route('login'));
});

test('sms history page is displayed for authenticated users', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('sms'))
        ->assertOk()
        ->assertSee('SMS History')
        ->assertSee('Outbound auto-replies saved from completed transactions.');
});

test('sms history page renders outbound auto reply transactions', function () {
    $user = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'transaction_id' => 'TX-SMS-123',
        'sender_phone' => '254712345678',
        'sender_name' => 'Jane Doe',
        'amount' => 50,
        'offer_name' => '1 GB Data',
        'status' => 'completed',
        'status_desc' => 'Transaction completed successfully.',
        'auto_reply_id' => null,
        'auto_reply_trigger_condition' => 'successful_transaction',
        'auto_reply_message' => 'Hello Jane, thanks for buying from Bingwa.',
        'auto_reply_recipient_phone' => '0712345678',
        'auto_reply_sim_slot' => 'slot_2',
        'auto_reply_status' => 'sent',
        'auto_reply_attempts' => 1,
        'auto_reply_sent_at' => now()->subMinute(),
        'auto_reply_failure_reason' => null,
    ]);

    $this->actingAs($user);

    Livewire::test('sms')
        ->assertSee('TX-SMS-123')
        ->assertSee('0712345678')
        ->assertSee('Successful Response')
        ->assertSee('Hello Jane, thanks for buying from Bingwa.')
        ->assertSee('Sent')
        ->assertSee('Slot 2');
});
