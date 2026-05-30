<?php

use App\Livewire\Actions\RecentTransactions;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('recent transactions render the latest records for the current user', function () {
    $user = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'sender_name' => 'Jane Doe',
        'sender_phone' => '0700123456',
        'offer_name' => '1 GB Data',
        'amount' => 19,
        'status' => 'completed',
        'status_desc' => 'Transaction completed successfully.',
    ]);

    $this->actingAs($user);

    Livewire::test(RecentTransactions::class)
        ->assertSee('Jane Doe')
        ->assertSee('1 GB Data')
        ->assertSee('Transaction completed successfully.')
        ->assertSee('Ksh 19.00')
        ->assertSee('wire:poll.visible.5s');
});
