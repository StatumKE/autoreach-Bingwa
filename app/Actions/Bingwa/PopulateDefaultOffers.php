<?php

namespace App\Actions\Bingwa;

use App\Models\Offer;
use App\Models\User;

class PopulateDefaultOffers
{
    /**
     * Populate default offers for the given user.
     */
    public function handle(User $user): void
    {
        $defaults = [
            [
                'name' => '1.5 GB - 3 Hrs',
                'category' => 'data',
                'price' => 50,
                'ussd_code' => '*180*5*2*BH*1*1#',
                'ussd_mode' => 'express',
                'is_active' => true,
            ],
            [
                'name' => '350 MBS - 7 Days',
                'category' => 'data',
                'price' => 49,
                'ussd_code' => '*180*5*2*BH*2*1#',
                'ussd_mode' => 'express',
                'is_active' => true,
            ],
            [
                'name' => '2.5GB - 7 Days',
                'category' => 'data',
                'price' => 300,
                'ussd_code' => '*180*5*2*BH*3*1#',
                'ussd_mode' => 'express',
                'is_active' => true,
            ],
            [
                'name' => '6GB - 7 Days',
                'category' => 'data',
                'price' => 700,
                'ussd_code' => '*180*5*2*BH*4*1#',
                'ussd_mode' => 'express',
                'is_active' => true,
            ],
            [
                'name' => '1GB - 1Hr',
                'category' => 'data',
                'price' => 19,
                'ussd_code' => '*180*5*2*BH*5*1#',
                'ussd_mode' => 'express',
                'is_active' => true,
            ],
            [
                'name' => '250MBS - 24 Hrs',
                'category' => 'data',
                'price' => 20,
                'ussd_code' => '*180*5*2*BH*6*1#',
                'ussd_mode' => 'express',
                'is_active' => true,
            ],
            [
                'name' => '1GB - 24 Hrs',
                'category' => 'data',
                'price' => 99,
                'ussd_code' => '*180*5*2*BH*7*1#',
                'ussd_mode' => 'express',
                'is_active' => true,
            ],
            [
                'name' => '1.25GB - Until Midnight',
                'category' => 'data',
                'price' => 55,
                'ussd_code' => '*180*5*2*BH*8*1#',
                'ussd_mode' => 'express',
                'is_active' => true,
            ],
        ];

        foreach ($defaults as $offerData) {
            Offer::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'ussd_code' => $offerData['ussd_code'],
                ],
                $offerData
            );
        }
    }
}
