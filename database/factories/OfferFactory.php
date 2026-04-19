<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
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
                '1 GB Data Bundle',
                'Night Airtime Pack',
                'Weekend SMS Blast',
            ]),
            'category' => fake()->randomElement(['data', 'airtime', 'sms']),
            'price' => fake()->numberBetween(10, 5000),
            'ussd_code' => fake()->randomElement(['*180*5*PN#', '*123#', '*544#']),
            'ussd_mode' => fake()->randomElement(['express', 'advanced']),
            'is_active' => fake()->boolean(80),
        ];
    }
}
