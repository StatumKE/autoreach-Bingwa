<?php

namespace Database\Factories;

use App\Models\DeviceSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceSetting>
 */
class DeviceSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'operator_identity' => fake()->name(),
            'primary_transaction_sim' => fake()->randomElement(['slot_1', 'slot_2']),
            'sms_auto_reply_sim' => fake()->randomElement(['slot_1', 'slot_2']),
            'app_interface_mode' => fake()->randomElement(['express', 'advanced']),
            'auto_reschedule_rejected' => true,
            'retry_tomorrow_at' => fake()->randomElement(['12:00 AM', '12:30 AM', '1:00 AM']),
            'ussd_timeout_seconds' => fake()->randomElement([20, 30, 45]),
            'intelligent_auto_retry' => true,
            'retry_interval_minutes' => fake()->randomElement([1, 3, 5]),
            'max_attempts' => fake()->randomElement([2, 3, 4]),
            'retry_network_issues' => true,
        ];
    }
}
