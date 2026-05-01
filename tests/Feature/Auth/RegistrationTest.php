<?php

use App\Actions\Autoreach\RegisterBingwaDevice;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Native\Mobile\Facades\Device;
use Ramsey\Uuid\Uuid;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    Device::shouldReceive('getId')->andReturn('HW-12345');

    $response = $this->get(route('register'));

    $response->assertOk();
    $response->assertSee('Autoreach Connect ID');
});

test('registration screen can be rendered before the local device registration table exists', function () {
    Schema::dropIfExists('bingwa_device_registrations');

    try {
        Device::shouldReceive('getId')->never();

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

test('registration screen is disabled on an already registered device', function () {
    Device::shouldReceive('getId')->andReturn('HW-12345');

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
    $response->assertSee('Log in');
    $response->assertSee('This device is already registered. Log in below, or use the APK on a new device to register another installation.');
    $response->assertDontSee('data-test="register-user-button"');
});

test('new users can register', function () {
    Device::shouldReceive('getId')->andReturn('HW-12345');
    Device::shouldReceive('getInfo')->andReturn(json_encode([
        'name' => 'Samsung A14',
        'model' => 'A145F',
        'manufacturer' => 'Samsung',
        'operatingSystem' => 'Android',
        'osVersion' => '14',
        'platform' => 'android',
        'isVirtual' => false,
    ]));
    Log::spy();

    Http::fake([
        'backend.statum.co.ke/api/v1/auth/device/register/hybrid' => Http::response([
            'device_token' => 'raw-device-token',
            'bhc_code' => 'BHC-ZXCVB',
            'device_id' => 42,
            'app_type' => 'hybridApp',
            'backend_device_type' => 'ussd_executor',
            'connect_device_id' => 'BHC-ZXCVB',
            'linked_connect_device_id' => 'BHC-ABCDE',
        ], 201),
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'autoreach_connect_id' => 'AC-12345',
        'password' => 'abcde',
        'password_confirmation' => 'abcde',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertCookie(Auth::getRecallerName())
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'autoreach_connect_id' => 'AC-12345',
    ]);

    $this->assertDatabaseHas('bingwa_device_registrations', [
        'hardware_id' => 'HW-12345',
        'bhc_code' => 'BHC-ZXCVB',
        'device_token' => 'raw-device-token',
    ]);

    $registration = BingwaDeviceRegistration::query()->where('hardware_id', 'HW-12345')->first();

    expect($registration)->not->toBeNull();
    expect($registration?->user_id)->toBe(auth()->id());

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://backend.statum.co.ke/api/v1/auth/device/register/hybrid'
            && $request['hardware_id'] === 'HW-12345'
            && $request['device_type'] === 'hybridApp'
            && $request['email'] === 'test@example.com'
            && $request['connect_device_id'] === 'AC-12345'
            && isset($request['device_info'])
            && ($request['device_info']['name'] ?? null) === 'Samsung A14';
    });

    Log::shouldHaveReceived('debug')
        ->with(
            'Submitting Bingwa device registration request.',
            Mockery::on(function (array $context): bool {
                return ($context['url'] ?? null) === 'https://backend.statum.co.ke/api/v1/auth/device/register/hybrid'
                    && ($context['payload']['hardware_id'] ?? null) === 'HW-12345'
                    && ($context['payload']['device_type'] ?? null) === 'hybridApp'
                    && ($context['payload']['email'] ?? null) === 'test@example.com'
                    && ($context['payload']['connect_device_id'] ?? null) === 'AC-12345'
                    && ($context['payload']['device_info']['name'] ?? null) === 'Samsung A14';
            })
        )
        ->once();
});

test('new users register with the native bridge hardware id when available', function () {
    $registrationAction = new class(['Device.GetId' => json_encode(['status' => 'success', 'data' => ['id' => 'android-id-123']]), 'Device.GetInfo' => json_encode(['status' => 'success', 'data' => ['info' => json_encode(['name' => 'Samsung A14', 'model' => 'A145F', 'manufacturer' => 'Samsung', 'operatingSystem' => 'Android', 'osVersion' => '14', 'platform' => 'android', 'isVirtual' => false])]])]) extends RegisterBingwaDevice
    {
        /**
         * @param  array<string, string>  $responses
         */
        public function __construct(private array $responses) {}

        protected function nativeBridgeCall(string $functionName, string $payload): mixed
        {
            return $this->responses[$functionName] ?? null;
        }
    };

    app()->instance(RegisterBingwaDevice::class, $registrationAction);

    Device::shouldReceive('getId')->never();
    Device::shouldReceive('getInfo')->never();

    Http::fake([
        'backend.statum.co.ke/api/v1/auth/device/register/hybrid' => Http::response([
            'device_token' => 'raw-device-token',
            'bhc_code' => 'BHC-ZXCVB',
            'device_id' => 42,
            'app_type' => 'hybridApp',
            'backend_device_type' => 'ussd_executor',
            'connect_device_id' => 'BHC-ZXCVB',
            'linked_connect_device_id' => 'BHC-ABCDE',
        ], 201),
    ]);

    $response = $this->post(route('register.store'), [
        'name' => 'Jane Doe',
        'email' => 'bridge@example.com',
        'autoreach_connect_id' => 'AC-12345',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $this->assertDatabaseHas('bingwa_device_registrations', [
        'hardware_id' => 'android-id-123',
        'bhc_code' => 'BHC-ZXCVB',
        'device_token' => 'raw-device-token',
    ]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://backend.statum.co.ke/api/v1/auth/device/register/hybrid'
            && $request['hardware_id'] === 'android-id-123'
            && ($request['device_info']['uuid'] ?? null) === 'android-id-123'
            && ($request['device_info']['name'] ?? null) === 'Samsung A14';
    });
});

test('registration is rolled back when the backend device registration fails', function () {
    Device::shouldReceive('getId')->andReturn('HW-12345');
    Device::shouldReceive('getInfo')->andReturn(json_encode([
        'name' => 'Samsung A14',
        'model' => 'A145F',
        'manufacturer' => 'Samsung',
        'operatingSystem' => 'Android',
        'osVersion' => '14',
        'platform' => 'android',
        'isVirtual' => false,
    ]));

    Http::fake([
        'backend.statum.co.ke/api/v1/auth/device/register/hybrid' => Http::response([
            'status' => 'failed',
            'message' => 'Validation failed',
            'errors' => [
                'connect_device_id' => ['The specified Connect device was not found for this account.'],
            ],
        ], 422),
    ]);

    $response = $this->from(route('register'))
        ->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'autoreach_connect_id' => 'AC-12345',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

    $response->assertRedirect(route('register'));
    $response->assertSessionHasErrors('connect_device_id');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', [
        'email' => 'test@example.com',
    ]);
    $this->assertDatabaseMissing('bingwa_device_registrations', [
        'hardware_id' => 'HW-12345',
    ]);
});

test('registration falls back to a uuid when the device id is unavailable', function () {
    Device::shouldReceive('getId')->andReturnNull();
    Device::shouldReceive('getInfo')->andReturn(json_encode([
        'name' => 'Android Emulator',
        'model' => 'sdk_gphone64_arm64',
        'manufacturer' => 'Google',
        'operatingSystem' => 'Android',
        'osVersion' => '15',
        'platform' => 'android',
        'isVirtual' => true,
    ]));

    Str::createUuidsUsing(function () {
        return Uuid::fromString('11111111-1111-4111-8111-111111111111');
    });

    Http::fake([
        'backend.statum.co.ke/api/v1/auth/device/register/hybrid' => Http::response([
            'device_token' => 'raw-device-token',
            'bhc_code' => 'BHC-ZXCVB',
            'device_id' => 42,
            'app_type' => 'hybridApp',
            'backend_device_type' => 'ussd_executor',
            'connect_device_id' => 'BHC-ZXCVB',
            'linked_connect_device_id' => 'BHC-ABCDE',
        ], 201),
    ]);

    try {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'uuid@example.com',
            'autoreach_connect_id' => 'AC-12345',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'email' => 'uuid@example.com',
            'autoreach_connect_id' => 'AC-12345',
        ]);

        $this->assertDatabaseHas('bingwa_device_registrations', [
            'hardware_id' => '11111111-1111-4111-8111-111111111111',
            'bhc_code' => 'BHC-ZXCVB',
            'device_token' => 'raw-device-token',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://backend.statum.co.ke/api/v1/auth/device/register/hybrid'
                && $request['hardware_id'] === '11111111-1111-4111-8111-111111111111'
                && $request['device_type'] === 'hybridApp'
                && $request['email'] === 'uuid@example.com'
                && $request['connect_device_id'] === 'AC-12345'
                && ($request['device_info']['uuid'] ?? null) === '11111111-1111-4111-8111-111111111111';
        });
    } finally {
        Str::createUuidsNormally();
    }
});

test('registration is blocked when the current device is already registered', function () {
    Device::shouldReceive('getId')->andReturn('HW-12345');

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'stored-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Http::fake();

    $response = $this->from(route('register'))
        ->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'autoreach_connect_id' => 'AC-12345',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

    $response->assertRedirect(route('register'));
    $response->assertSessionHasErrors('email');

    $this->assertDatabaseMissing('users', [
        'email' => 'test@example.com',
    ]);

    Http::assertNothingSent();
});
