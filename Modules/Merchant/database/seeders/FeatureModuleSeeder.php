<?php
// database/seeders/FeatureModuleSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeatureModuleSeeder extends Seeder
{
    public function run(): void
    {
        $map = [
            'multi_warehouse' => ['multi_warehouse', 'stock_transfer'],
            'export_report' => ['export_pdf', 'export_excel'],
            'product_unit' => ['product_unit_conversion'],
            'table_management' => ['table_layout_designer'],
            'kitchen_display' => ['kitchen_order_ticket'],
            'whatsapp_notification' => ['wa_order_notification', 'wa_payment_reminder'],
            'loyalty_program' => ['loyalty_points', 'loyalty_voucher'],
        ];

        foreach ($map as $moduleCode => $featureCodes) {
            $moduleId = DB::table('modules')->where('code', $moduleCode)->value('id');

            foreach ($featureCodes as $featureCode) {
                $featureId = DB::table('features')->where('code', $featureCode)->value('id');

                DB::table('feature_modules')->insert([
                    'feature_id' => $featureId,
                    'module_id' => $moduleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}