<?php

use App\Models\DeviceSetting;
use App\Models\User;
use Livewire\Livewire;

test('device settings page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('device.edit'))
        ->assertOk()
        ->assertSee('Autoreach Bingwa')
        ->assertSeeInOrder(['Quick Dial', 'Device', 'Hardware'])
        ->assertDontSee('Security')
        ->assertSee('SIM Slot Mapping')
        ->assertSee('Android')
        ->assertDontSee('Android Unknown')
        ->assertDontSee('App interface mode')
        ->assertSee('Retry & resilience rules');
});

test('device settings can be updated and stored in the database', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.device')
        ->set('operator_identity', 'Bob Mwenda')
        ->call('saveOperatorIdentity')
        ->set('primary_transaction_sim', 'slot_2')
        ->set('sms_auto_reply_sim', 'slot_1')
        ->call('saveHardwareMapping')
        ->set('auto_reschedule_rejected', true)
        ->set('retry_tomorrow_at', '12:30 AM')
        ->set('ussd_timeout_seconds', '45')
        ->set('intelligent_auto_retry', true)
        ->set('retry_interval_minutes', '3')
        ->set('max_attempts', '4')
        ->set('retry_network_issues', true)
        ->call('saveTechnicalConfig');

    $component->assertHasNoErrors();

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'operator_identity' => 'Bob Mwenda',
        'primary_transaction_sim' => 'slot_2',
        'sms_auto_reply_sim' => 'slot_1',
        'auto_reschedule_rejected' => true,
        'retry_tomorrow_at' => '12:30 AM',
        'ussd_timeout_seconds' => 45,
        'intelligent_auto_retry' => true,
        'retry_interval_minutes' => 3,
        'max_attempts' => 4,
        'retry_network_issues' => true,
    ]);
});

test('sim slot mapping can be updated independently', function () {
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

    Livewire::test('pages::settings.device')
        ->set('primary_transaction_sim', 'slot_2')
        ->set('sms_auto_reply_sim', 'slot_2')
        ->call('saveHardwareMapping')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('device_settings', [
        'user_id' => $user->id,
        'primary_transaction_sim' => 'slot_2',
        'sms_auto_reply_sim' => 'slot_2',
    ]);
});

test('device settings mount from the existing database record', function () {
    $user = User::factory()->create();

    DeviceSetting::query()->create([
        'user_id' => $user->id,
        'operator_identity' => 'Alice Admin',
        'primary_transaction_sim' => 'slot_2',
        'sms_auto_reply_sim' => 'slot_1',
        'auto_reschedule_rejected' => false,
        'retry_tomorrow_at' => null,
        'ussd_timeout_seconds' => 30,
        'intelligent_auto_retry' => true,
        'retry_interval_minutes' => 5,
        'max_attempts' => 2,
        'retry_network_issues' => false,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.device');

    $component->assertSet('operator_identity', 'Alice Admin');
    $component->assertSet('primary_transaction_sim', 'slot_2');
    $component->assertSet('sms_auto_reply_sim', 'slot_1');
    $component->assertSet('auto_reschedule_rejected', false);
    $component->assertSet('retry_interval_minutes', '5');
    $component->assertSet('retry_network_issues', false);
});
