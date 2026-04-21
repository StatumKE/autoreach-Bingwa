<?php

use App\Models\Transaction;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('Autoreach Bingwa');
});

test('dashboard no longer renders a current balance card when historical balance data exists', function () {
    $user = User::factory()->create();

    Transaction::factory()->for($user)->create([
        'amount' => 125,
        'status' => 'completed',
        'occurred_at' => now(),
        'balance' => [
            'airtime' => 420,
        ],
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Used Today');
    $response->assertDontSee('Current Balance');
});
