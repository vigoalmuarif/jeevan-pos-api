<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            \Modules\Operational\Database\Seeders\WilayahSeeder::class,
            \Modules\Merchant\Database\Seeders\ModuleSeeder::class,
            \Modules\Merchant\Database\Seeders\FeatureSeeder::class,
            \Modules\Merchant\Database\Seeders\FeatureModuleSeeder::class,
            

            \Modules\Merchant\Database\Seeders\PlanSeeder::class,
            \Modules\Merchant\Database\Seeders\PlanPriceSeeder::class,
            \Modules\Merchant\Database\Seeders\PlanLimitSeeder::class,
            \Modules\Merchant\Database\Seeders\PlanModuleSeeder::class,

            \Modules\User\Database\Seeders\EmployeeSeeder::class,
            \Modules\Permission\Database\Seeders\PermissionSeeder::class,

            \Modules\Merchant\Database\Seeders\IndustryPackageSeeder::class,
            \Modules\Merchant\Database\Seeders\IndustryPackagePriceSeeder::class,
            \Modules\Merchant\Database\Seeders\IndustryPackageModuleSeeder::class,
            
            \Modules\Merchant\Database\Seeders\AddonSeeder::class,

            // PRODUCT ATRIBUTTE
            \Modules\Product\Database\Seeders\UnitSeeder::class,

            \Modules\Permission\Database\Seeders\MenuSeeder::class,

        ]);
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
