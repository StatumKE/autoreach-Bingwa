<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'transaction_id' => 'TX-'.fake()->unique()->numerify('########'),
            'mpesa_code' => 'MP'.fake()->unique()->bothify('###??'),
            'sender_phone' => '2547'.fake()->numerify('#######'),
            'sender_name' => fake()->name(),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'offer_name' => fake()->randomElement([
                '1 GB Data Bundle',
                'Night Airtime Pack',
                'Weekend SMS Blast',
            ]),
            'offer_type' => fake()->randomElement(['data', 'airtime', 'sms']),
            'matched_offer' => fake()->randomElement([
                [
                    'offer_local_id' => '9',
                    'offer_key' => 'test_1_day',
                    'offer_name' => 'Test 1 day',
                    'offer_type' => 'data_bundles',
                    'offer_amount' => 20,
                ],
                [
                    'offer_local_id' => '3',
                    'offer_key' => 'sms_basic',
                    'offer_name' => 'SMS Basic',
                    'offer_type' => 'sms',
                    'offer_amount' => 10,
                ],
                null,
            ]),
            'balance' => fake()->randomElement([
                [
                    'device_account_id' => 1,
                    'tokens' => [
                        'included' => 10,
                        'consumed' => 4,
                        'balance' => 6,
                        'remaining' => 6,
                    ],
                ],
                null,
            ]),
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'status' => fake()->randomElement(['pending', 'successful', 'failed']),
            'status_desc' => fake()->randomElement([
                'Transaction queued for processing.',
                'Transaction completed successfully.',
                'Transaction could not be matched.',
            ]),
        ];
    }
}
