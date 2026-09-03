<?php
// database/seeders/IndustryPackageModuleSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IndustryPackageModuleSeeder extends Seeder
{
    public function run(): void
    {
        // package_code => [module_codes]
        // multi_warehouse contoh module yang shared antar Retail & Distributor (no double charge)
        $map = [
            'retail' => [
                'product_unit',
                'multi_warehouse',
                'loyalty_program',
            ],
            'cafe' => [
                'table_management',
                'kitchen_display',
                'reservation',
            ],
            'distributor' => [
                'delivery_order',
                'salesman',
                'multi_warehouse', // shared dengan retail
            ],
            'building_material' => [
                'material_calculation',
                'project_management',
                'product_unit', // shared dengan retail
            ],
            'wholesale' => [
                'product_unit',
                'multi_warehouse',
            ],
        ];

        foreach ($map as $packageCode => $moduleCodes) {
            $packageId = DB::table('industry_packages')->where('code', $packageCode)->value('id');

            foreach ($moduleCodes as $moduleCode) {
                $moduleId = DB::table('modules')->where('code', $moduleCode)->value('id');

                DB::table('industry_package_modules')->insert([
                    'industry_package_id' => $packageId,
                    'module_id' => $moduleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}