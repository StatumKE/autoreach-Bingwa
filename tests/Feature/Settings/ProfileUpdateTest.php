<?php

use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('profile page is displayed', function () {
    config()->set('nativephp.app_version', '2.4.6');
    config()->set('nativephp.app_version_code', 42);

    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Autoreach Connect ID')
        ->assertSee('Email is linked to your device account and cannot be edited here.')
        ->assertSee('Autoreach Connect ID is tied to the registered device and cannot be edited here.')
        ->assertSee('App version')
        ->assertSee('2.4.6')
        ->assertSee('Build number')
        ->assertSee('42');
});

test('profile information can be updated without changing locked fields', function () {
    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
        'autoreach_connect_id' => 'AC-11111',
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('autoreach_connect_id', 'AC-98765')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('original@example.com');
    expect($user->autoreach_connect_id)->toEqual('AC-11111');
    expect($user->email_verified_at)->not->toBeNull();
});

test('device token can be recovered from the backend', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'old-device-token',
        'bhc_code' => 'BHC-ZXCVB',
        'backend_device_id' => 42,
        'app_type' => 'hybridApp',
        'backend_device_type' => 'ussd_executor',
        'connect_device_id' => 'AC-12345',
        'linked_connect_device_id' => 'BHC-ABCDE',
        'device_name' => 'Samsung A14',
        'app_version' => '1.2.0',
        'device_info' => [
            'name' => 'Samsung A14',
            'model' => 'A145F',
            'manufacturer' => 'Samsung',
            'os_name' => 'Android',
            'os_version' => '14',
            'platform' => 'android',
            'is_virtual' => false,
            'uuid' => 'HW-12345',
        ],
    ]);

    Http::fake([
        'backend.statum.co.ke/api/v1/auth/device/token/recover' => Http::response([
            'status' => 'success',
            'message' => 'Device token recovered. Store this token securely; it will not be shown again.',
            'device_token' => 'new-device-token',
            'device_id' => 42,
            'bhc_code' => 'BHC-ZXCVB',
        ], 200),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->call('recoverDeviceToken');

    $response->assertHasNoErrors();

    expect($user->refresh()->bingwaDeviceRegistration)->not->toBeNull();
    expect($user->bingwaDeviceRegistration?->device_token)->toEqual('new-device-token');
    expect($user->bingwaDeviceRegistration?->backend_device_id)->toEqual(42);

    Http::assertSent(function ($request) use ($user): bool {
        return $request->url() === 'https://backend.statum.co.ke/api/v1/auth/device/token/recover'
            && $request['email'] === $user->email
            && $request['hardware_id'] === 'HW-12345'
            && $request['bhc_code'] === 'BHC-ZXCVB';
    });
});

test('device token recovery surfaces backend validation errors', function () {
    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'old-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Http::fake([
        'backend.statum.co.ke/api/v1/auth/device/token/recover' => Http::response([
            'status' => 'failed',
            'message' => 'Validation failed',
            'errors' => [
                'hardware_id' => ['Hardware ID does not match the registered device.'],
            ],
        ], 422),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->call('recoverDeviceToken');

    $response->assertHasErrors(['hardware_id']);

    expect($user->refresh()->bingwaDeviceRegistration?->device_token)->toEqual('old-device-token');
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->set('autoreach_connect_id', $user->autoreach_connect_id)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
