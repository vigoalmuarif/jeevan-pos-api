<?php
// database/seeders/PlanLimitSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanLimitSeeder extends Seeder
{
    public function run(): void
    {
        // plan_code => [limit_key => limit_value], -1 = unlimited
        $limits = [
            'free' => [
                'max_users' => 2,
                'max_branches' => 1,
                'max_warehouses' => 1,
                'max_products' => 50,
                'max_transactions_per_month' => 100,
            ],
            'basic' => [
                'max_users' => 5,
                'max_branches' => 1,
                'max_warehouses' => 2,
                'max_products' => 500,
                'max_transactions_per_month' => 1000,
            ],
            'pro' => [
                'max_users' => 20,
                'max_branches' => 5,
                'max_warehouses' => 5,
                'max_products' => 5000,
                'max_transactions_per_month' => 10000,
            ],
            'enterprise' => [
                'max_users' => -1,
                'max_branches' => -1,
                'max_warehouses' => -1,
                'max_products' => -1,
                'max_transactions_per_month' => -1,
            ],
        ];

        foreach ($limits as $planCode => $planLimits) {
            $planId = DB::table('plans')->where('code', $planCode)->value('id');

            foreach ($planLimits as $key => $value) {
                DB::table('plan_limits')->insert([
                    'plan_id' => $planId,
                    'limit_key' => $key,
                    'limit_value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}