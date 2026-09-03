<?php
// database/seeders/FeatureSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            ['code' => 'multi_warehouse', 'name' => 'Multi Warehouse Stock'],
            ['code' => 'stock_transfer', 'name' => 'Stock Transfer Antar Gudang'],
            ['code' => 'export_pdf', 'name' => 'Export PDF'],
            ['code' => 'export_excel', 'name' => 'Export Excel'],
            ['code' => 'product_unit_conversion', 'name' => 'Konversi Satuan Produk'],
            ['code' => 'table_layout_designer', 'name' => 'Table Layout Designer'],
            ['code' => 'kitchen_order_ticket', 'name' => 'Kitchen Order Ticket (KOT)'],
            ['code' => 'wa_order_notification', 'name' => 'Notifikasi Order via WhatsApp'],
            ['code' => 'wa_payment_reminder', 'name' => 'Reminder Pembayaran via WhatsApp'],
            ['code' => 'loyalty_points', 'name' => 'Poin Loyalti'],
            ['code' => 'loyalty_voucher', 'name' => 'Voucher Reward'],
        ];

        foreach ($features as $feature) {
            DB::table('features')->insert([
                ...$feature,
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}