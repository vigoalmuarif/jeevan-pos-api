<?php

namespace Modules\Permission\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permission\Models\Permission;
use Modules\Permission\Services\PermissionCatalogService;

class PermissionSeeder extends Seeder
{
    public function __construct(protected PermissionCatalogService $permissionCatalogService) {}

    public function run(): void
    {
        $permissions = $this->permissions();
        
        $this->permissionCatalogService->forget();

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                [
                    'name'       => $permission['name'],
                    'guard_name' => 'merchant',
                ],
                [
                    'group'        => $permission['group'],
                    'display_name' => $permission['display_name'],
                    'description'  => $permission['description'] ?? null,
                ]
            );
        }
    }

    private function permissions(): array
    {
        return [
            // ------------------------------------------------
            // User Management
            // ------------------------------------------------
            ['group' => 'user', 'name' => 'core.user.view', 'module_code' => 'user', 'display_name' => 'Lihat User'],
            ['group' => 'user', 'name' => 'core.user.create', 'module_code' => 'user', 'display_name' => 'Tambah User'],
            ['group' => 'user', 'name' => 'core.user.update', 'module_code' => 'user', 'display_name' => 'Edit User'],
            ['group' => 'user', 'name' => 'core.user.delete', 'module_code' => 'user', 'display_name' => 'Hapus User'],

            // ------------------------------------------------
            // Role & Permission
            // ------------------------------------------------
            ['group' => 'role', 'name' => 'core.role.view', 'module_code' => 'permission',   'display_name' => 'Lihat Role'],
            ['group' => 'role', 'name' => 'core.role.create', 'module_code' => 'permission', 'display_name' => 'Tambah Role'],
            ['group' => 'role', 'name' => 'core.role.update', 'module_code' => 'permission', 'display_name' => 'Edit Role'],
            ['group' => 'role', 'name' => 'core.role.delete', 'module_code' => 'permission', 'display_name' => 'Hapus Role'],

            // ------------------------------------------------
            // Regional
            // ------------------------------------------------
            ['group' => 'regional', 'name' => 'core.regional.view', 'module_code' => 'operational',   'display_name' => 'Lihat Regional'],
            ['group' => 'regional', 'name' => 'core.regional.create', 'module_code' => 'operational', 'display_name' => 'Tambah Regional'],
            ['group' => 'regional', 'name' => 'core.regional.update', 'module_code' => 'operational', 'display_name' => 'Edit Regional'],
            ['group' => 'regional', 'name' => 'core.regional.delete', 'module_code' => 'operational', 'display_name' => 'Hapus Regional'],

            // ------------------------------------------------
            // Branch
            // ------------------------------------------------
            ['group' => 'branch', 'name' => 'core.branch.view', 'module_code' => 'operational',   'display_name' => 'Lihat Cabang'],
            ['group' => 'branch', 'name' => 'core.branch.create', 'module_code' => 'operational', 'display_name' => 'Tambah Cabang'],
            ['group' => 'branch', 'name' => 'core.branch.update', 'module_code' => 'operational', 'display_name' => 'Edit Cabang'],
            ['group' => 'branch', 'name' => 'core.branch.delete', 'module_code' => 'operational', 'display_name' => 'Hapus Cabang'],

            // ------------------------------------------------
            // Warehouse
            // ------------------------------------------------
            ['group' => 'warehouse', 'name' => 'core.warehouse.view', 'module_code' => 'operational',    'display_name' => 'Lihat Gudang'],
            ['group' => 'warehouse', 'name' => 'core.warehouse.create', 'module_code' => 'operational',  'display_name' => 'Tambah Gudang'],
            ['group' => 'warehouse', 'name' => 'core.warehouse.update', 'module_code' => 'operational',  'display_name' => 'Edit Gudang'],
            ['group' => 'warehouse', 'name' => 'core.warehouse.delete', 'module_code' => 'operational',  'display_name' => 'Hapus Gudang'],

            
            // ------------------------------------------------
            // Product
            // ------------------------------------------------
            ['group' => 'product', 'name' => 'product.view', 'module_code' => 'product',    'display_name' => 'Lihat Produk'],
            ['group' => 'product', 'name' => 'product.create', 'module_code' => 'product',  'display_name' => 'Tambah Produk'],
            ['group' => 'product', 'name' => 'product.update', 'module_code' => 'product',  'display_name' => 'Edit Produk'],
            ['group' => 'product', 'name' => 'product.delete', 'module_code' => 'product',  'display_name' => 'Hapus Produk'],

            // ------------------------------------------------
            // Category
            // ------------------------------------------------
            ['group' => 'category', 'name' => 'product.category.view', 'module_code' => 'product',    'display_name' => 'Lihat Kategori'],
            ['group' => 'category', 'name' => 'product.category.create', 'module_code' => 'product',  'display_name' => 'Tambah Kategori'],
            ['group' => 'category', 'name' => 'product.category.update', 'module_code' => 'product',  'display_name' => 'Edit Kategori'],
            ['group' => 'category', 'name' => 'product.category.delete', 'module_code' => 'product',  'display_name' => 'Hapus Kategori'],


            // ------------------------------------------------
            // Unit Product
            // ------------------------------------------------
            ['group' => 'unit', 'name' => 'product.unit.view', 'module_code' => 'product',    'display_name' => 'Lihat Satuan'],
            ['group' => 'unit', 'name' => 'product.unit.create', 'module_code' => 'product',  'display_name' => 'Tambah Satuan'],
            ['group' => 'unit', 'name' => 'product.unit.update', 'module_code' => 'product',  'display_name' => 'Edit Satuan'],
            ['group' => 'unit', 'name' => 'product.unit.delete', 'module_code' => 'product',  'display_name' => 'Hapus Satuan'],

            // ------------------------------------------------
            // Unit Product
            // ------------------------------------------------
            ['group' => 'brand', 'name' => 'product.brand.view', 'module_code' => 'product',    'display_name' => 'Lihat Merek'],
            ['group' => 'brand', 'name' => 'product.brand.create', 'module_code' => 'product',  'display_name' => 'Tambah Merek'],
            ['group' => 'brand', 'name' => 'product.brand.update', 'module_code' => 'product',  'display_name' => 'Edit Merek'],
            ['group' => 'brand', 'name' => 'product.brand.delete', 'module_code' => 'product',  'display_name' => 'Hapus Merek'],

            // ------------------------------------------------
            // Customer
            // ------------------------------------------------
            ['group' => 'customer', 'name' => 'customer.view',  'module_code' => 'customer',    'display_name' => 'Lihat Pelanggan'],
            ['group' => 'customer', 'name' => 'customer.create', 'module_code' => 'customer',  'display_name' => 'Tambah Pelanggan'],
            ['group' => 'customer', 'name' => 'customer.update', 'module_code' => 'customer',  'display_name' => 'Edit Pelanggan'],
            ['group' => 'customer', 'name' => 'customer.delete', 'module_code' => 'customer',  'display_name' => 'Hapus Pelanggan'],

            // ------------------------------------------------
            // Supplier
            // ------------------------------------------------
            ['group' => 'supplier', 'name' => 'supplier.view', 'module_code' => 'supplier',    'display_name' => 'Lihat Supplier'],
            ['group' => 'supplier', 'name' => 'supplier.create', 'module_code' => 'supplier',  'display_name' => 'Tambah Supplier'],
            ['group' => 'supplier', 'name' => 'supplier.update', 'module_code' => 'supplier',  'display_name' => 'Edit Supplier'],
            ['group' => 'supplier', 'name' => 'supplier.delete', 'module_code' => 'supplier',  'display_name' => 'Hapus Supplier'],

            // ------------------------------------------------
            // Purchase
            // ------------------------------------------------
            ['group' => 'purchase', 'name' => 'purchase.view', 'module_code' => 'purchase',    'display_name' => 'Lihat Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.create', 'module_code' => 'purchase',  'display_name' => 'Tambah Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.update', 'module_code' => 'purchase',  'display_name' => 'Edit Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.delete', 'module_code' => 'purchase',  'display_name' => 'Hapus Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.approve', 'module_code' => 'purchase', 'display_name' => 'Approve Pembelian'],

            // ------------------------------------------------
            // Sales
            // ------------------------------------------------
            ['group' => 'sales', 'name' => 'sales.view', 'module_code' => 'sales',    'display_name' => 'Lihat Penjualan'],
            ['group' => 'sales', 'name' => 'sales.create', 'module_code' => 'sales',  'display_name' => 'Buat Transaksi'],
            ['group' => 'sales', 'name' => 'sales.void', 'module_code' => 'sales',    'display_name' => 'Void Transaksi'],
            ['group' => 'sales', 'name' => 'sales.refund', 'module_code' => 'sales',  'display_name' => 'Refund Transaksi'],
            ['group' => 'sales', 'name' => 'sales.report', 'module_code' => 'sales',  'display_name' => 'Laporan Penjualan'],
            ['group' => 'sales', 'name' => 'sales.discount', 'module_code' => 'sales', 'display_name'=> 'Beri Diskon'],

            // ------------------------------------------------
            // Inventory
            // ------------------------------------------------
            ['group' => 'inventory', 'name' => 'inventory.view', 'module_code' => 'inventory',      'display_name' => 'Lihat Stok'],
            ['group' => 'inventory', 'name' => 'inventory.movement.view', 'module_code' => 'inventory',      'display_name' => 'Lihat Mutasi Stok'],
            ['group' => 'inventory', 'name' => 'inventory.adjustment.view', 'module_code' => 'inventory',    'display_name' => 'Adjustment Stok'],
            ['group' => 'inventory', 'name' => 'inventory.adjustment.create', 'module_code' => 'inventory',    'display_name' => 'Buat Adjustment Stok'],
            ['group' => 'inventory', 'name' => 'inventory.adjustment.approve', 'module_code' => 'inventory',    'display_name' => 'Approve Adjustment Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.view', 'module_code' => 'inventory',  'display_name' => 'Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.create', 'module_code' => 'inventory',  'display_name' => 'Buat Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.approve', 'module_code' => 'inventory',  'display_name' => 'Approve Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.report', 'module_code' => 'inventory',    'display_name' => 'Laporan Stok'],

            // ------------------------------------------------
            // Dashboard
            // ------------------------------------------------
            ['group' => 'dashboard', 'name' => 'core.dashboard.view', 'module_code' => 'dashboard', 'display_name' => 'Lihat Dashboard'],

            // ------------------------------------------------
            // Setting
            // ------------------------------------------------
            ['group' => 'setting', 'name' => 'core.setting.view', 'module_code' => 'setting',   'display_name' => 'Lihat Pengaturan'],
            ['group' => 'setting', 'name' => 'core.setting.update', 'module_code' => 'setting', 'display_name' => 'Edit Pengaturan'],
        ];
    }
}