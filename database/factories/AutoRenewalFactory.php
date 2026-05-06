<?php

namespace Database\Factories;

use App\Models\AutoRenewal;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoRenewal>
 */
class AutoRenewalFactory extends Factory
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
            'offer_id' => Offer::factory(),
            'customer_phone' => '07'.fake()->numerify('########'),
            'scheduled_for' => fake()->dateTimeBetween('now', '+14 days'),
            'auto_renew' => fake()->boolean(80),
            'renew_days' => fake()->numberBetween(1, 30),
            'status' => fake()->randomElement(['scheduled', 'processing', 'completed', 'failed', 'cancelled']),
            'last_attempt_at' => fake()->optional()->dateTimeBetween('-5 days', 'now'),
            'processed_at' => fake()->optional()->dateTimeBetween('-5 days', 'now'),
            'cancelled_at' => fake()->optional()->dateTimeBetween('-5 days', 'now'),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
