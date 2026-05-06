<?php

namespace Database\Factories;

use App\Models\AutoReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoReply>
 */
class AutoReplyFactory extends Factory
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
            'name' => fake()->randomElement([
                'Successful Response',
                'Failed Request',
                'Unavailable Offer',
            ]),
            'trigger_condition' => fake()->randomElement([
                'successful_transaction',
                'failed_transaction',
                'offer_unavailable',
                'already_recommended',
                'app_paused',
                'blacklisted_customer',
            ]),
            'reply_message' => fake()->sentence(14),
            'is_active' => fake()->boolean(60),
            'is_default' => fake()->boolean(40),
            'sort_order' => fake()->numberBetween(0, 99),
        ];
    }
}
