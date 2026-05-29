<?php

use App\Jobs\SyncBingwaFcmTokenJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
    $response->assertSee('Autoreach Connect ID');
});

test('registration screen can be rendered before the local device registration table exists', function () {
    Schema::dropIfExists('bingwa_device_registrations');

    try {
        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertSee('Autoreach Connect ID');
    } finally {
        if (! Schema::hasTable('bingwa_device_registrations')) {
            $migration = require database_path('migrations/2026_04_19_060832_create_bingwa_device_registrations_table.php');
            $migration->up();
        }
    }
});

test('registration screen hides the form when a local account already exists for a registered device', function () {
    $user = User::factory()->create([
        'email' => 'registered@example.com',
    ]);

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'stored-device-token',
        'bhc_code' => 'BHC-ZXCVB',
        'backend_device_id' => 42,
        'app_type' => 'hybridApp',
        'backend_device_type' => 'ussd_executor',
        'connect_device_id' => 'AC-12345',
        'linked_connect_device_id' => 'BHC-ABCDE',
    ]);

    $response = $this->get(route('register'));

    $response->assertOk();
    $response->assertSee('This account is already registered on this device.');
    $response->assertDontSee('Autoreach Connect ID');
});

test('registration screen hides the form after a single local account exists', function () {
    User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $response = $this->get(route('register'));

    $response->assertOk();
    $response->assertSee('This account is already registered on this device.');
    $response->assertDontSee('Autoreach Connect ID');
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'autoreach_connect_id' => 'AC-12345',
        'password' => 'abcde',
        'password_confirmation' => 'abcde',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertSessionHas('request_setup_permissions_after_onboarding', true)
        ->assertCookie(Auth::getRecallerName())
        ->assertRedirect(route('setup', absolute: false));

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'autoreach_connect_id' => 'AC-12345',
    ]);

    $this->assertDatabaseHas('device_settings', [
        'user_id' => auth()->id(),
        'operator_identity' => 'John Doe',
        'primary_transaction_sim' => 'slot_1',
    ]);

    $this->assertDatabaseMissing('bingwa_device_registrations', [
        'user_id' => auth()->id(),
    ]);
});

test('new users register without waiting for backend device sync', function () {
    Queue::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'Jane Doe',
        'email' => 'bridge@example.com',
        'autoreach_connect_id' => 'AC-12345',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertSessionHas('request_setup_permissions_after_onboarding', true)
        ->assertRedirect(route('setup', absolute: false));

    $this->assertAuthenticated();

    $this->assertDatabaseMissing('bingwa_device_registrations', [
        'user_id' => auth()->id(),
    ]);

    Queue::assertPushed(SyncBingwaFcmTokenJob::class);
});

test('registration does not depend on backend device registration being available', function () {
    $response = $this->from(route('register'))
        ->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'autoreach_connect_id' => 'AC-12345',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

    $response->assertSessionHasNoErrors()
        ->assertSessionHas('request_setup_permissions_after_onboarding', true)
        ->assertRedirect(route('setup', absolute: false));

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'autoreach_connect_id' => 'AC-12345',
    ]);

    $this->assertDatabaseHas('device_settings', [
        'user_id' => auth()->id(),
        'operator_identity' => 'John Doe',
        'primary_transaction_sim' => 'slot_1',
    ]);

    $this->assertDatabaseMissing('bingwa_device_registrations', [
        'user_id' => auth()->id(),
    ]);
});

test('registration is blocked when a local user already exists', function () {
    User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'second@example.com',
        'autoreach_connect_id' => 'AC-12345',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors([
        'email' => 'This app already has a registered account. Only one account is supported per installation.',
    ]);

    $this->assertGuest();
    $this->assertDatabaseCount('users', 1);
});
