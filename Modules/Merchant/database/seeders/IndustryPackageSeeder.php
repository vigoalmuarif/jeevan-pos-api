<?php
// database/seeders/IndustryPackageSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IndustryPackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            ['code' => 'retail', 'name' => 'Retail / Minimarket', 'description' => 'Paket untuk toko retail dan minimarket'],
            ['code' => 'cafe', 'name' => 'Cafe & Restaurant', 'description' => 'Paket untuk cafe dan restoran'],
            ['code' => 'distributor', 'name' => 'Distributor', 'description' => 'Paket untuk usaha distribusi'],
            ['code' => 'building_material', 'name' => 'Toko Bangunan', 'description' => 'Paket untuk toko material bangunan'],
            ['code' => 'laundry', 'name' => 'Laundry', 'description' => 'Paket untuk loundry'],
            ['code' => 'wholesale', 'name' => 'Toko Grosir', 'description' => 'Paket untuk toko grosir'],
        ];

        foreach ($packages as $package) {
            DB::table('industry_packages')->insert([
                ...$package,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}