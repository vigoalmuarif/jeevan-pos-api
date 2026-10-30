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
        $this->sync();
    }

    /**
     * Sinkronkan katalog permission sistem dan hapus cache katalog.
     *
     * @return int jumlah definisi permission yang diproses
     */
    public function sync(): int
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

        return count($permissions);
    }

    /**
     * @return list<array{group:string,name:string,module_code:string,display_name:string,description?:string|null}>
     */
    public function permissions(): array
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
            ['group' => 'customer', 'name' => 'partner.customer.view',  'module_code' => 'partner',    'display_name' => 'Lihat Pelanggan'],
            ['group' => 'customer', 'name' => 'partner.customer.create', 'module_code' => 'partner',  'display_name' => 'Tambah Pelanggan'],
            ['group' => 'customer', 'name' => 'partner.customer.update', 'module_code' => 'partner',  'display_name' => 'Edit Pelanggan'],
            ['group' => 'customer', 'name' => 'partner.customer.delete', 'module_code' => 'partner',  'display_name' => 'Hapus Pelanggan'],

            // ------------------------------------------------
            // Supplier
            // ------------------------------------------------
            ['group' => 'supplier', 'name' => 'partner.supplier.view', 'module_code' => 'partner',    'display_name' => 'Lihat Supplier'],
            ['group' => 'supplier', 'name' => 'partner.supplier.create', 'module_code' => 'partner',  'display_name' => 'Tambah Supplier'],
            ['group' => 'supplier', 'name' => 'partner.supplier.update', 'module_code' => 'partner',  'display_name' => 'Edit Supplier'],
            ['group' => 'supplier', 'name' => 'partner.supplier.delete', 'module_code' => 'partner',  'display_name' => 'Hapus Supplier'],

            // ------------------------------------------------
            // Sales
            // ------------------------------------------------
            ['group' => 'sales', 'name' => 'sales.view', 'module_code' => 'sales',    'display_name' => 'Lihat Penjualan'],
            ['group' => 'sales', 'name' => 'sales.create', 'module_code' => 'sales',  'display_name' => 'Buat Transaksi'],
            ['group' => 'sales', 'name' => 'sales.void', 'module_code' => 'sales',    'display_name' => 'Void Transaksi'],
            ['group' => 'sales', 'name' => 'sales.refund', 'module_code' => 'sales',  'display_name' => 'Refund Transaksi'],
            ['group' => 'sales', 'name' => 'sales.report', 'module_code' => 'sales',  'display_name' => 'Laporan Penjualan'],
            ['group' => 'sales', 'name' => 'sales.discount', 'module_code' => 'sales', 'display_name' => 'Beri Diskon'],

            // ------------------------------------------------
            // Inventory
            // ------------------------------------------------
            ['group' => 'inventory', 'name' => 'inventory.view', 'module_code' => 'inventory',      'display_name' => 'Lihat Stok'],

            ['group' => 'inventory', 'name' => 'inventory.movement.view', 'module_code' => 'inventory',      'display_name' => 'Lihat Mutasi Stok'],

            ['group' => 'inventory', 'name' => 'inventory.adjustment.view', 'module_code' => 'inventory',    'display_name' => 'Adjustment Stok'],
            ['group' => 'inventory', 'name' => 'inventory.adjustment.create', 'module_code' => 'inventory',    'display_name' => 'Buat Adjustment Stok'],
            ['group' => 'inventory', 'name' => 'inventory.adjustment.approve', 'module_code' => 'inventory',    'display_name' => 'Approve Adjustment Stok'],

            #Transfer stok

            ['group' => 'inventory', 'name' => 'inventory.transfer.view', 'module_code' => 'inventory',  'display_name' => 'Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.create', 'module_code' => 'inventory',  'display_name' => 'Buat Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.approve', 'module_code' => 'inventory',  'display_name' => 'Approve Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.update',  'module_code' => 'inventory', 'display_name' => 'Ubah Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.delete',  'module_code' => 'inventory', 'display_name' => 'Hapus Draft Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.ship',    'module_code' => 'inventory', 'display_name' => 'Kirim Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.receive', 'module_code' => 'inventory', 'display_name' => 'Terima Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.transfer.cancel',  'module_code' => 'inventory', 'display_name' => 'Batalkan Transfer Stok'],
            ['group' => 'inventory', 'name' => 'inventory.report', 'module_code' => 'inventory',    'display_name' => 'Laporan Stok'],


            // ------------------------------------------------
            // Purchase
            // ------------------------------------------------

            ['group' => 'purchase', 'name' => 'purchase.request.view',      'module_code' => 'purchase', 'display_name' => 'Lihat Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.create',    'module_code' => 'purchase', 'display_name' => 'Buat Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.update',    'module_code' => 'purchase', 'display_name' => 'Ubah Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.delete',    'module_code' => 'purchase', 'display_name' => 'Hapus Draft Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.submit',    'module_code' => 'purchase', 'display_name' => 'Ajukan Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.approve',   'module_code' => 'purchase', 'display_name' => 'Setujui Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.reject',    'module_code' => 'purchase', 'display_name' => 'Tolak Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.convert',   'module_code' => 'purchase', 'display_name' => 'Jadikan PO dari Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.cancel',    'module_code' => 'purchase', 'display_name' => 'Batalkan Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.close',     'module_code' => 'purchase', 'display_name' => 'Tutup Purchase Request'],
            ['group' => 'purchase', 'name' => 'purchase.request.recommend', 'module_code' => 'purchase', 'display_name' => 'Lihat Rekomendasi Pembelian'],


            ['group' => 'purchase', 'name' => 'purchase.order.view',    'module_code' => 'purchase', 'display_name' => 'Lihat Purchase Order'],
            ['group' => 'purchase', 'name' => 'purchase.order.create',  'module_code' => 'purchase', 'display_name' => 'Buat Purchase Order'],
            ['group' => 'purchase', 'name' => 'purchase.order.update',  'module_code' => 'purchase', 'display_name' => 'Ubah Purchase Order'],
            ['group' => 'purchase', 'name' => 'purchase.order.delete',  'module_code' => 'purchase', 'display_name' => 'Hapus Draft Purchase Order'],
            ['group' => 'purchase', 'name' => 'purchase.order.approve', 'module_code' => 'purchase', 'display_name' => 'Approve Purchase Order'],
            ['group' => 'purchase', 'name' => 'purchase.order.cancel',  'module_code' => 'purchase', 'display_name' => 'Batalkan Purchase Order'],
            ['group' => 'purchase', 'name' => 'purchase.order.close',   'module_code' => 'purchase', 'display_name' => 'Tutup Purchase Order'],

            ['group' => 'purchase', 'name' => 'purchase.receipt.view',   'module_code' => 'purchase', 'display_name' => 'Lihat Penerimaan Barang'],
            ['group' => 'purchase', 'name' => 'purchase.receipt.create', 'module_code' => 'purchase', 'display_name' => 'Buat Penerimaan Barang'],
            ['group' => 'purchase', 'name' => 'purchase.receipt.update', 'module_code' => 'purchase', 'display_name' => 'Ubah Penerimaan Barang'],
            ['group' => 'purchase', 'name' => 'purchase.receipt.delete', 'module_code' => 'purchase', 'display_name' => 'Hapus Draft Penerimaan Barang'],
            ['group' => 'purchase', 'name' => 'purchase.receipt.post',   'module_code' => 'purchase', 'display_name' => 'Posting Penerimaan Barang'],
            ['group' => 'purchase', 'name' => 'purchase.receipt.void',   'module_code' => 'purchase', 'display_name' => 'Batalkan Penerimaan Barang'],

            ['group' => 'purchase', 'name' => 'purchase.invoice.view',   'module_code' => 'purchase', 'display_name' => 'Lihat Faktur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.invoice.create', 'module_code' => 'purchase', 'display_name' => 'Buat Faktur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.invoice.update', 'module_code' => 'purchase', 'display_name' => 'Ubah Faktur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.invoice.delete', 'module_code' => 'purchase', 'display_name' => 'Hapus Draft Faktur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.invoice.post',   'module_code' => 'purchase', 'display_name' => 'Posting Faktur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.invoice.void',   'module_code' => 'purchase', 'display_name' => 'Batalkan Faktur Pembelian'],

            ['group' => 'purchase', 'name' => 'purchase.payment.view',   'module_code' => 'purchase', 'display_name' => 'Lihat Pembayaran Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.payment.create', 'module_code' => 'purchase', 'display_name' => 'Buat Pembayaran Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.payment.void',   'module_code' => 'purchase', 'display_name' => 'Batalkan Pembayaran Pembelian'],

            // Purchase Return (DEC-10)
            ['group' => 'purchase', 'name' => 'purchase.return.view',   'module_code' => 'purchase', 'display_name' => 'Lihat Retur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.return.create', 'module_code' => 'purchase', 'display_name' => 'Buat Retur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.return.update', 'module_code' => 'purchase', 'display_name' => 'Ubah Retur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.return.delete', 'module_code' => 'purchase', 'display_name' => 'Hapus Draft Retur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.return.post',   'module_code' => 'purchase', 'display_name' => 'Posting Retur Pembelian'],
            ['group' => 'purchase', 'name' => 'purchase.return.void',   'module_code' => 'purchase', 'display_name' => 'Batalkan Retur Pembelian'],


            // Stok Minimum
            ['group' => 'inventory', 'name' => 'inventory.threshold.view',   'module_code' => 'inventory', 'display_name' => 'Lihat Stok Minimum'],
            ['group' => 'inventory', 'name' => 'inventory.threshold.update', 'module_code' => 'inventory', 'display_name' => 'Atur Stok Minimum'],

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
