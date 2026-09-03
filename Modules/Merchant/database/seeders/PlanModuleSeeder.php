<?php
// database/seeders/PlanModuleSeeder.php
// plan_modules = module yang INCLUDED GRATIS (core untuk semua plan,
// + module business/addon tertentu khusus plan Enterprise)

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanModuleSeeder extends Seeder
{
    public function run(): void
    {
        $allPlanCodes = DB::table('plans')->pluck('id', 'code');

        // 1. Semua module core → included gratis di SEMUA plan
        $coreModuleIds = DB::table('modules')->where('category', 'core')->pluck('id');

        foreach ($allPlanCodes as $planCode => $planId) {
            foreach ($coreModuleIds as $moduleId) {
                DB::table('plan_modules')->insert([
                    'plan_id' => $planId,
                    'module_id' => $moduleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 2. Module business/addon tertentu → included gratis HANYA untuk plan Enterprise
        $enterprisePlanId = $allPlanCodes['enterprise'];

        $freeForEnterprise = [
            'multi_warehouse',       // addon, biasanya berbayar tapi gratis untuk enterprise
            'export_report',         // addon
            'whatsapp_notification', // addon
        ];

        foreach ($freeForEnterprise as $moduleCode) {
            $moduleId = DB::table('modules')->where('code', $moduleCode)->value('id');

            DB::table('plan_modules')->insert([
                'plan_id' => $enterprisePlanId,
                'module_id' => $moduleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}