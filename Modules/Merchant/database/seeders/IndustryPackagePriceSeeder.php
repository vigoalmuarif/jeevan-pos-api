<?php
// database/seeders/IndustryPackagePriceSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IndustryPackagePriceSeeder extends Seeder
{
    public function run(): void
    {
        // package_code => [monthly, yearly]
        $prices = [
            'retail'            => [50000, 500000],
            'cafe'              => [75000, 750000],
            'distributor'       => [80000, 800000],
            'building_material' => [70000, 700000],
            'wholesale'         => [60000, 600000],
        ];

        foreach ($prices as $code => [$monthly, $yearly]) {
            $id = DB::table('industry_packages')->where('code', $code)->value('id');

            DB::table('industry_package_prices')->insert([
                [
                    'industry_package_id' => $id,
                    'billing_cycle' => 'monthly',
                    'price' => $monthly,
                    'currency' => 'IDR',
                    'valid_from' => now(),
                    'valid_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'industry_package_id' => $id,
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