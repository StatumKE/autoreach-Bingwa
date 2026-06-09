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

test('it falls back to advanced mode when express mode returns general failure and preferred mode is express', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->for($user)->create([
        'primary_transaction_sim' => 'slot_1',
        'app_interface_mode' => 'express',
        'airtime_balance' => 10.0,
    ]);

    $GLOBALS['custom_nativephp_call_mock'] = function (string $function, ?string $payload): string {
        $decoded = json_decode($payload, true);
        if ($decoded['mode'] === 'express') {
            return '{"success":false,"message":"Network returned a general failure"}';
        }
        if ($decoded['mode'] === 'advanced') {
            return '{"success":true,"message":"Airtime Bal: 150.00KSH"}';
        }

        return '{"success":false,"message":"Unknown mode"}';
    };

    $result = app(RefreshAirtimeBalance::class)->refresh($user);

    expect($result['balance'])->toBe(150.0);
    expect($result['raw_response'])->toBe('Airtime Bal: 150.00KSH');

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'airtime_balance' => 150.0,
        'airtime_balance_raw_response' => 'Airtime Bal: 150.00KSH',
    ]);

    // Verify both express and advanced mode queries were dispatched sequentially
    expect($GLOBALS['nativephp_call_calls'])->toHaveCount(2);
    expect($GLOBALS['nativephp_call_calls'][0]['payload']['mode'])->toBe('express');
    expect($GLOBALS['nativephp_call_calls'][1]['payload']['mode'])->toBe('advanced');
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

test('it preserves cached balance when both express and fallback advanced mode query fail', function (): void {
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

    // Verify both express and advanced mode queries were attempted
    expect($GLOBALS['nativephp_call_calls'])->toHaveCount(2);
    expect($GLOBALS['nativephp_call_calls'][0]['payload']['mode'])->toBe('express');
    expect($GLOBALS['nativephp_call_calls'][1]['payload']['mode'])->toBe('advanced');
});

test('it queries advanced mode directly if app interface mode is set to advanced', function (): void {
    $user = User::factory()->create();
    DeviceSetting::factory()->for($user)->create([
        'primary_transaction_sim' => 'slot_1',
        'app_interface_mode' => 'advanced',
        'airtime_balance' => 10.0,
    ]);

    $GLOBALS['custom_nativephp_call_mock'] = function (string $function, ?string $payload): string {
        $decoded = json_decode($payload, true);
        if ($decoded['mode'] === 'advanced') {
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

    // Verify only advanced mode query was dispatched
    expect($GLOBALS['nativephp_call_calls'])->toHaveCount(1);
    expect($GLOBALS['nativephp_call_calls'][0]['payload']['mode'])->toBe('advanced');
});
