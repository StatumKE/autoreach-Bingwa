<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

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

test('the native php android shell uses classic runtime mode', function () {
    expect(config('nativephp.runtime.mode'))->toBe('classic');
});

test('the native php android shell runs migrations during filesystem prep', function () {
    $environmentSource = File::get(base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt'));

    expect($environmentSource)
        ->toContain('runRequiredMigrations()')
        ->toContain('phpBridge.runArtisanCommand("migrate --force --no-interaction")');
});
