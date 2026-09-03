<?php
// database/seeders/AddonSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AddonSeeder extends Seeder
{
    public function run(): void
    {
        $loyaltyModuleId = DB::table('modules')->where('code', 'loyalty_program')->value('id');
        $whatsappModuleId = DB::table('modules')->where('code', 'whatsapp_notification')->value('id');
        $reservationModuleId = DB::table('modules')->where('code', 'reservation')->value('id');

        $addons = [
            // per_unit
            [
                'code' => 'extra_user',
                'name' => 'Tambahan User',
                'type' => 'per_unit',
                'target_limit_key' => 'max_users',
                'bundle_quantity' => null,
                'module_id' => null,
                'prices' => ['monthly' => 5000, 'yearly' => 50000],
            ],
            [
                'code' => 'extra_branch',
                'name' => 'Tambahan Cabang',
                'type' => 'per_unit',
                'target_limit_key' => 'max_branches',
                'bundle_quantity' => null,
                'module_id' => null,
                'prices' => ['monthly' => 25000, 'yearly' => 250000],
            ],

            // bundle
            [
                'code' => 'extra_product_100',
                'name' => 'Paket +100 Produk',
                'type' => 'bundle',
                'target_limit_key' => 'max_products',
                'bundle_quantity' => 100,
                'module_id' => null,
                'prices' => ['monthly' => 15000, 'yearly' => 150000],
            ],
            [
                'code' => 'extra_transaction_1000',
                'name' => 'Paket +1000 Transaksi/Bulan',
                'type' => 'bundle',
                'target_limit_key' => 'max_transactions_per_month',
                'bundle_quantity' => 1000,
                'module_id' => null,
                'prices' => ['monthly' => 20000, 'yearly' => 200000],
            ],

            // module
            [
                'code' => 'addon_loyalty_program',
                'name' => 'Loyalty Program',
                'type' => 'module',
                'target_limit_key' => null,
                'bundle_quantity' => null,
                'module_id' => $loyaltyModuleId,
                'prices' => ['monthly' => 45000, 'yearly' => 450000],
            ],
            [
                'code' => 'addon_whatsapp_notification',
                'name' => 'WhatsApp Notification',
                'type' => 'module',
                'target_limit_key' => null,
                'bundle_quantity' => null,
                'module_id' => $whatsappModuleId,
                'prices' => ['monthly' => 35000, 'yearly' => 350000],
            ],
            [
                'code' => 'addon_reservation',
                'name' => 'Reservation Module',
                'type' => 'module',
                'target_limit_key' => null,
                'bundle_quantity' => null,
                'module_id' => $reservationModuleId,
                'prices' => ['monthly' => 40000, 'yearly' => 400000],
            ],
        ];

        foreach ($addons as $addon) {
            $prices = $addon['prices'];
            unset($addon['prices']);

            $addonId = DB::table('addons')->insertGetId([
                ...$addon,
                'description' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($prices as $cycle => $price) {
                DB::table('addon_prices')->insert([
                    'addon_id' => $addonId,
                    'billing_cycle' => $cycle,
                    'price' => $price,
                    'currency' => 'IDR',
                    'valid_from' => now(),
                    'valid_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}