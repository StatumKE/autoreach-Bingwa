<?php

use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Laravel\Fortify\Features;
use Native\Mobile\Facades\Device;

test('login form shows submission feedback', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('x-on:submit="submitting = true"', false)
        ->assertSee('Logging in…');
});

test('register form shows submission feedback', function () {
    $this->skipUnlessFortifyHas(Features::registration());

    Device::shouldReceive('getId')->andReturn('HW-12345');

    $this->get(route('register'))
        ->assertOk()
        ->assertSee('data-test="register-user-button"', false)
        ->assertSee('Create Account');
});

test('password and verification forms show submission feedback', function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    $user = User::factory()->unverified()->create();

    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('x-on:submit="submitting = true"', false)
        ->assertSee('Sending…');

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('x-on:submit="submitting = true"', false)
        ->assertSee('Confirming…');

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk()
        ->assertSee('x-on:submit="submitting = true"', false)
        ->assertSee('Sending…')
        ->assertSee('Logging out…');
});

test('settings forms show loading feedback', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'stored-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('wire:target="updateProfileInformation"', false)
        ->assertSee('Saving…')
        ->assertSee('Recovering…');

    $this->actingAs($user)
        ->get(route('device.edit'))
        ->assertOk()
        ->assertSee('wire:target="saveOperatorIdentity"', false)
        ->assertSee('wire:target="saveHardwareMapping"', false)
        ->assertSee('wire:target="saveTechnicalConfig"', false)
        ->assertSee('Saving…')
        ->assertSee('Applying…');
});
