<?php
// database/seeders/ModuleSeeder.php

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Module;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            // Core Business
            ['code' => 'auth', 'name' => 'Authentication', 'category' => 'core'],
            ['code' => 'user', 'name' => 'User Management', 'category' => 'core'],
            ['code' => 'permission', 'name' => 'Role & Permission', 'category' => 'core'],
            ['code' => 'product', 'name' => 'Product', 'category' => 'core'],
            ['code' => 'operational', 'name' => 'Operational', 'category' => 'core'],
            ['code' => 'customer', 'name' => 'Customer', 'category' => 'core'],
            ['code' => 'supplier', 'name' => 'Supplier', 'category' => 'core'],
            ['code' => 'sales', 'name' => 'Sales', 'category' => 'core'],
            ['code' => 'purchase', 'name' => 'Purchase', 'category' => 'core'],
            ['code' => 'inventory', 'name' => 'Inventory', 'category' => 'core'],
            ['code' => 'dashboard', 'name' => 'Dashboard', 'category' => 'core'],
            ['code' => 'setting', 'name' => 'Setting', 'category' => 'core'],

            // Business Industry — Cafe / Restaurant
            ['code' => 'table_management', 'name' => 'Table Management', 'category' => 'business'],
            ['code' => 'kitchen_display', 'name' => 'Kitchen Display System', 'category' => 'business'],
            ['code' => 'reservation', 'name' => 'Reservation', 'category' => 'business'],

            // Business — Toko Bangunan / Grosir
            ['code' => 'material_calculation', 'name' => 'Material Calculation', 'category' => 'business'],
            ['code' => 'project_management', 'name' => 'Project Management', 'category' => 'business'],
            ['code' => 'product_unit', 'name' => 'Multi Unit Product', 'category' => 'business'],

            // Business — Distributor
            ['code' => 'delivery_order', 'name' => 'Delivery Order', 'category' => 'business'],
            ['code' => 'salesman', 'name' => 'Salesman Management', 'category' => 'business'],

            // Addons
            ['code' => 'whatsapp_notification', 'name' => 'WhatsApp Notification', 'category' => 'addon'],
            ['code' => 'export_report', 'name' => 'Export Report (Excel/PDF)', 'category' => 'addon'],
            ['code' => 'multi_warehouse', 'name' => 'Multi Warehouse', 'category' => 'addon'],
            ['code' => 'loyalty_program', 'name' => 'Loyalty Program', 'category' => 'addon'],
        ];
        DB::raw('delete from modules');
        foreach ($modules as $module) {
            Module::updateOrCreate(
                [...$module],
                [
                    'description' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
