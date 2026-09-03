<?php
// database/seeders/PlanPriceSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanPriceSeeder extends Seeder
{
    public function run(): void
    {
        // plan_code => [monthly, yearly]
        $prices = [
            'trial'      => [0, 0],
            'free'       => [0, 0],
            'basic'      => [150000, 1500000],
            'pro'        => [350000, 3500000],
            'enterprise' => [750000, 7500000],
        ];

        foreach ($prices as $planCode => [$monthly, $yearly]) {
            $planId = DB::table('plans')->where('code', $planCode)->value('id');

            DB::table('plan_prices')->insert([
                [
                    'plan_id' => $planId,
                    'billing_cycle' => 'monthly',
                    'price' => $monthly,
                    'currency' => 'IDR',
                    'valid_from' => now(),
                    'valid_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'plan_id' => $planId,
                    'billing_cycle' => 'yearly',
                    'price' => $yearly,
                    'currency' => 'IDR',
                    'valid_from' => now(),
                    'valid_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}