<?php

use App\Models\User;

test('guest users are redirected to the login screen from the mobile home route', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});

test('authenticated users are redirected to the dashboard from the mobile home route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});

test('the native php android shell is configured for light status bar icons', function () {
    expect(config('nativephp.android.status_bar_style'))->toBe('light');
});
