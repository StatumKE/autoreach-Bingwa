<?php

use App\Models\DeviceSetting;
use App\Models\User;

test('device settings page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('device.edit'))
        ->assertOk()
        ->assertSee('Device Configuration')
        ->assertSee('SIM Slot Mapping')
        ->assertSee('Automation Rules')
        ->assertSee('Grant All Permissions')
        ->assertSee('Autoreach Bingwa')
        ->assertSee('name="primary_transaction_sim"', false)
        ->assertSee('name="sms_auto_reply_sim"', false)
        ->assertSee('data-hardware-action-template=', false)
        ->assertSee('/settings/device/hardware/__PRIMARY_SIM__/__SMS_SIM__', false)
        ->assertSee('<select', false)
        ->assertDontSee('wire:model');
});

test('operator identity can be updated with a plain post form', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->from(route('device.edit'))
        ->post(route('device.identity.update'), [
            'operator_identity' => 'Bob Mwenda',
        ])
        ->assertRedirect(route('device.edit'));

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'operator_identity' => 'Bob Mwenda',
    ]);
});

test('sim slot mapping can be updated with a plain post form', function () {
    $user = User::factory()->create();

    DeviceSetting::query()->create([
        'user_id' => $user->id,
        'operator_identity' => 'Alice Admin',
        'primary_transaction_sim' => 'slot_1',
        'sms_auto_reply_sim' => 'slot_1',
        'auto_reschedule_rejected' => false,
        'retry_tomorrow_at' => null,
        'ussd_timeout_seconds' => 30,
        'intelligent_auto_retry' => true,
        'retry_interval_minutes' => 1,
        'max_attempts' => 2,
        'retry_network_issues' => false,
    ]);

    $this->actingAs($user);

    $this->from(route('device.edit'))
        ->post(route('device.hardware.update'), [
            'primary_transaction_sim' => 'slot_2',
            'sms_auto_reply_sim' => 'slot_1',
        ])
        ->assertRedirect(route('device.edit'));

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'primary_transaction_sim' => 'slot_2',
        'sms_auto_reply_sim' => 'slot_1',
    ]);
});

test('sim slot mapping can be updated when android drops the post body', function () {
    $user = User::factory()->create();

    DeviceSetting::query()->create([
        'user_id' => $user->id,
        'operator_identity' => 'Alice Admin',
        'primary_transaction_sim' => 'slot_1',
        'sms_auto_reply_sim' => 'slot_1',
        'auto_reschedule_rejected' => false,
        'retry_tomorrow_at' => null,
        'ussd_timeout_seconds' => 30,
        'intelligent_auto_retry' => true,
        'retry_interval_minutes' => 1,
        'max_attempts' => 2,
        'retry_network_issues' => false,
    ]);

    $this->actingAs($user);

    $this->from(route('device.edit'))
        ->post(route('device.hardware.update.path', [
            'primaryTransactionSim' => 'slot_2',
            'smsAutoReplySim' => 'slot_2',
        ]))
        ->assertRedirect(route('device.edit'));

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'primary_transaction_sim' => 'slot_2',
        'sms_auto_reply_sim' => 'slot_2',
    ]);
});

test('sim slot mapping renders the persisted values instead of stale old input', function () {
    $user = User::factory()->create();

    DeviceSetting::query()->create([
        'user_id' => $user->id,
        'operator_identity' => 'Alice Admin',
        'primary_transaction_sim' => 'slot_2',
        'sms_auto_reply_sim' => 'slot_2',
        'auto_reschedule_rejected' => false,
        'retry_tomorrow_at' => null,
        'ussd_timeout_seconds' => 30,
        'intelligent_auto_retry' => true,
        'retry_interval_minutes' => 1,
        'max_attempts' => 2,
        'retry_network_issues' => false,
    ]);

    $this->actingAs($user)
        ->withSession([
            '_old_input' => [
                'primary_transaction_sim' => 'slot_1',
                'sms_auto_reply_sim' => 'slot_1',
            ],
        ]);

    $this->get(route('device.edit'))
        ->assertOk()
        ->assertSee('value="slot_2" selected', false)
        ->assertDontSee('value="slot_1" selected', false);
});

test('android fallback get after hardware save redirects back to device settings', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/settings/device/hardware')
        ->assertRedirect(route('device.edit'));

    $this->get('/settings/device/hardware/slot_2/slot_2')
        ->assertRedirect(route('device.edit'));
});

test('technical settings can be updated with a plain post form', function () {
    $user = User::factory()->create();

    DeviceSetting::query()->create([
        'user_id' => $user->id,
        'operator_identity' => 'Alice Admin',
        'primary_transaction_sim' => 'slot_1',
        'sms_auto_reply_sim' => 'slot_1',
        'auto_reschedule_rejected' => false,
        'retry_tomorrow_at' => null,
        'ussd_timeout_seconds' => 30,
        'intelligent_auto_retry' => true,
        'retry_interval_minutes' => 1,
        'max_attempts' => 2,
        'retry_network_issues' => false,
    ]);

    $this->actingAs($user);

    $this->from(route('device.edit'))
        ->post(route('device.technical.update'), [
            'auto_reschedule_rejected' => '1',
            'retry_tomorrow_at' => '12:30 AM',
            'ussd_timeout_seconds' => '45',
            'intelligent_auto_retry' => '1',
            'retry_interval_minutes' => '3',
            'max_attempts' => '4',
            'retry_network_issues' => '1',
        ])
        ->assertRedirect(route('device.edit'));

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'auto_reschedule_rejected' => true,
        'retry_tomorrow_at' => '12:30 AM',
        'ussd_timeout_seconds' => 45,
        'intelligent_auto_retry' => true,
        'retry_interval_minutes' => 3,
        'max_attempts' => 4,
        'retry_network_issues' => true,
    ]);
});
