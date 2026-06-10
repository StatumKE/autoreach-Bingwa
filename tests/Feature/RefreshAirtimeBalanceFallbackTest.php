<?php

use App\Actions\Autoreach\RefreshAirtimeBalance;
use App\Models\DeviceSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    $GLOBALS['nativephp_call_calls'] = [];
    $GLOBALS['nativephp_call_mock'] = function (string $function, ?string $payload = null): string {
        $GLOBALS['nativephp_call_calls'][] = [
            'function' => $function,
            'payload' => json_decode($payload, true),
        ];

        if (isset($GLOBALS['custom_nativephp_call_mock'])) {
            return $GLOBALS['custom_nativephp_call_mock']($function, $payload);
        }

        return '{"success":true,"message":"Airtime Bal: 100.00KSH"}';
    };
});

afterEach(function (): void {
    unset($GLOBALS['nativephp_call_mock']);
    unset($GLOBALS['custom_nativephp_call_mock']);
    unset($GLOBALS['nativephp_call_calls']);
});

test('it does not fall back to advanced mode when express mode fails', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->for($user)->create([
        'primary_transaction_sim' => 'slot_1',
        'app_interface_mode' => 'express',
        'airtime_balance' => 10.0,
        'airtime_balance_raw_response' => 'Initial response',
    ]);

    $GLOBALS['custom_nativephp_call_mock'] = function (string $function, ?string $payload): string {
        return '{"success":false,"message":"Network returned a general failure"}';
    };

    $result = app(RefreshAirtimeBalance::class)->refresh($user);

    // Should return cached values because express failed and fallback did not run
    expect($result['balance'])->toBe(10.0);
    expect($result['raw_response'])->toBe('Initial response');

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'airtime_balance' => 10.0,
        'airtime_balance_raw_response' => 'Initial response',
    ]);

    // Verify only express mode query was attempted (count = 1)
    expect($GLOBALS['nativephp_call_calls'])->toHaveCount(1);
    expect($GLOBALS['nativephp_call_calls'][0]['payload']['mode'])->toBe('express');
});

test('it does not trigger fallback when express mode succeeds directly', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->for($user)->create([
        'primary_transaction_sim' => 'slot_1',
        'app_interface_mode' => 'express',
        'airtime_balance' => 10.0,
    ]);

    $GLOBALS['custom_nativephp_call_mock'] = function (string $function, ?string $payload): string {
        $decoded = json_decode($payload, true);
        if ($decoded['mode'] === 'express') {
            return '{"success":true,"message":"KSh 75.50 balance"}';
        }

        return '{"success":false,"message":"Should not be called"}';
    };

    $result = app(RefreshAirtimeBalance::class)->refresh($user);

    expect($result['balance'])->toBe(75.5);
    expect($result['raw_response'])->toBe('KSh 75.50 balance');

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'airtime_balance' => 75.5,
    ]);

    // Verify only express mode query was dispatched
    expect($GLOBALS['nativephp_call_calls'])->toHaveCount(1);
    expect($GLOBALS['nativephp_call_calls'][0]['payload']['mode'])->toBe('express');
});

test('it preserves cached balance when express mode query fails', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->for($user)->create([
        'primary_transaction_sim' => 'slot_1',
        'app_interface_mode' => 'express',
        'airtime_balance' => 10.50,
        'airtime_balance_raw_response' => 'Initial raw response',
    ]);

    $GLOBALS['custom_nativephp_call_mock'] = function (string $function, ?string $payload): string {
        return '{"success":false,"message":"Network returned a general failure"}';
    };

    $result = app(RefreshAirtimeBalance::class)->refresh($user);

    // Should return the cached value
    expect($result['balance'])->toBe(10.50);
    expect($result['raw_response'])->toBe('Initial raw response');

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'airtime_balance' => 10.50,
        'airtime_balance_raw_response' => 'Initial raw response',
    ]);

    // Verify only express query was attempted
    expect($GLOBALS['nativephp_call_calls'])->toHaveCount(1);
    expect($GLOBALS['nativephp_call_calls'][0]['payload']['mode'])->toBe('express');
});

test('it queries express mode even if app interface mode is set to advanced', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->for($user)->create([
        'primary_transaction_sim' => 'slot_1',
        'app_interface_mode' => 'advanced',
        'airtime_balance' => 10.0,
    ]);

    $GLOBALS['custom_nativephp_call_mock'] = function (string $function, ?string $payload): string {
        $decoded = json_decode($payload, true);
        if ($decoded['mode'] === 'express') {
            return '{"success":true,"message":"KSh 45.20"}';
        }

        return '{"success":false,"message":"Should not be called"}';
    };

    $result = app(RefreshAirtimeBalance::class)->refresh($user);

    expect($result['balance'])->toBe(45.20);
    expect($result['raw_response'])->toBe('KSh 45.20');

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'airtime_balance' => 45.20,
    ]);

    // Verify only express mode query was dispatched, even though settings preferred advanced
    expect($GLOBALS['nativephp_call_calls'])->toHaveCount(1);
    expect($GLOBALS['nativephp_call_calls'][0]['payload']['mode'])->toBe('express');
});
