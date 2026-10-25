<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        foreach (['Samsung', 'Apple', 'Djarum', 'Gudang Garam', 'Danone'] as $name) {
            $brand = DB::table('brands')->whereNull('merchant_id')->where('name', $name)->first();
            $values = ['is_active' => true, 'deleted_at' => null, 'updated_at' => $now];
            if ($brand) {
                DB::table('brands')->where('id', $brand->id)->update($values);
            } else {
                DB::table('brands')->insert([
                    'merchant_id' => null,
                    'name' => $name,
                    ...$values,
                    'created_at' => $now,
                ]);
            }
        }
    }
}
