<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
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
            'code' => 'plan_'.strtolower($this->faker->word()),
            'name' => $this->faker->word().' Plan',
            'type' => 'usage_pack',
            'price' => 100,
            'is_active' => true,
            'ussd_requests_included' => 100,
            'ussd_counter' => 0,
            'duration_days' => 30,
            'expires_at' => now()->addDays(30),
        ];
    }
}
