<?php

namespace Tests\Feature\Purchase;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\ExtPurchaseRequest\Services\PurchaseRequestService;
use Modules\Merchant\Models\Merchant;
use Tests\TestCase;

class PurchaseRequestProductOptionsTest extends TestCase
{
    private Merchant $merchant;
    private PurchaseRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        MerchantContext::clear();

        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('These tests require the isolated in-memory SQLite database from phpunit.xml.');
        }

        $this->createSchema();

        DB::table('merchants')->insert([
            'id' => 1,
            'name' => 'Merchant Test',
            'metadata' => json_encode(['inventory' => ['allow_negative_stock' => false]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->merchant = Merchant::query()->findOrFail(1);
        MerchantContext::set($this->merchant);

        // Warehouses & Branches
        DB::table('branches')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Cabang 1', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Cabang 2', 'is_active' => true],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Gudang Cabang 1', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Gudang Cabang 2', 'is_active' => true],
            ['id' => 3, 'merchant_id' => 1, 'name' => 'Gudang Pusat Mandiri', 'is_active' => true],
        ]);
        DB::table('branch_warehouses')->insert([
            ['merchant_id' => 1, 'branch_id' => 1, 'warehouse_id' => 1, 'is_active' => true],
            ['merchant_id' => 1, 'branch_id' => 2, 'warehouse_id' => 2, 'is_active' => true],
        ]);

        // Units
        DB::table('units')->insert([
            ['id' => 1, 'merchant_id' => null, 'name' => 'Pcs', 'code' => 'PCS', 'is_active' => true],
            ['id' => 2, 'merchant_id' => null, 'name' => 'Box', 'code' => 'BOX', 'is_active' => true],
        ]);

        // Suppliers
        DB::table('suppliers')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Supplier A', 'code' => 'SUP-A', 'is_active' => true],
        ]);

        // Products:
        // Product 1: Dijual (can_be_sold = true)
        DB::table('products')->insert([
            'id' => 1, 'merchant_id' => 1, 'name' => 'Produk Jual 1', 'type' => 'product',
            'track_stock' => true, 'is_active' => true, 'can_be_sold' => true,
        ]);
        DB::table('product_variants')->insert([
            'id' => 10, 'merchant_id' => 1, 'product_id' => 1, 'name' => 'Varian Jual 1', 'sku' => 'SKU-JUAL-1', 'is_active' => true,
        ]);
        DB::table('product_variant_units')->insert([
            'merchant_id' => 1, 'product_variant_id' => 10, 'unit_id' => 1, 'cost' => 5000, 'is_active' => true,
        ]);
        DB::table('product_unit_conversions')->insert([
            'merchant_id' => 1, 'product_id' => 1, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true,
        ]);

        // Product 2: Dijual (can_be_sold = true, HANYA untuk Cabang 1)
        DB::table('products')->insert([
            'id' => 2, 'merchant_id' => 1, 'name' => 'Produk Jual 2 Khusus Cabang 1', 'type' => 'product',
            'track_stock' => true, 'is_active' => true, 'can_be_sold' => true,
        ]);
        DB::table('product_variants')->insert([
            'id' => 20, 'merchant_id' => 1, 'product_id' => 2, 'name' => 'Varian Jual 2', 'sku' => 'SKU-JUAL-2', 'is_active' => true,
        ]);
        DB::table('product_variant_units')->insert([
            'merchant_id' => 1, 'product_variant_id' => 20, 'unit_id' => 1, 'cost' => 12000, 'is_active' => true,
        ]);
        DB::table('product_unit_conversions')->insert([
            'merchant_id' => 1, 'product_id' => 2, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true,
        ]);

        // Product 3: Non-Jual / Bahan Baku (can_be_sold = false)
        DB::table('products')->insert([
            'id' => 3, 'merchant_id' => 1, 'name' => 'Bahan Baku Kopi', 'type' => 'product',
            'track_stock' => true, 'is_active' => true, 'can_be_sold' => false,
        ]);
        DB::table('product_variants')->insert([
            'id' => 30, 'merchant_id' => 1, 'product_id' => 3, 'name' => 'Biji Kopi 1kg', 'sku' => 'SKU-BAHAN-1', 'is_active' => true,
        ]);
        DB::table('product_variant_units')->insert([
            'merchant_id' => 1, 'product_variant_id' => 30, 'unit_id' => 1, 'cost' => 80000, 'is_active' => true,
        ]);
        DB::table('product_unit_conversions')->insert([
            'merchant_id' => 1, 'product_id' => 3, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true,
        ]);

        // Stock in Warehouse 1:
        // Variant 10 (Jual 1): 25 pcs
        // Variant 30 (Bahan): 100 pcs
        DB::table('warehouse_stocks')->insert([
            ['merchant_id' => 1, 'warehouse_id' => 1, 'product_variant_id' => 10, 'quantity' => 25.0000, 'avg_cost' => 5000],
            ['merchant_id' => 1, 'warehouse_id' => 1, 'product_variant_id' => 30, 'quantity' => 100.0000, 'avg_cost' => 80000],
        ]);

        // Branch 1 has active assortment: only variant 20
        DB::table('branch_active_products')->insert([
            ['merchant_id' => 1, 'branch_id' => 1, 'product_variant_id' => 20, 'source' => 'assortment', 'resolved_at' => now()],
        ]);

        $this->service = app(PurchaseRequestService::class);
    }

    public function test_standalone_warehouse_returns_all_sales_and_non_sales_products(): void
    {
        // Warehouse 3 is not linked to any branch (independent central warehouse)
        $options = $this->service->searchProductOptions([
            'warehouse_id' => 3,
        ]);

        $variantIds = collect($options)->pluck('product_variant_id')->all();

        // Must include all sales items (10, 20) and non-sales item (30)
        $this->assertContains(10, $variantIds);
        $this->assertContains(20, $variantIds);
        $this->assertContains(30, $variantIds);
    }

    public function test_warehouse_only_filters_by_linked_branch_assortment(): void
    {
        // Warehouse 1 is linked to Branch 1 in branch_warehouses.
        // Branch 1 only has variant 20 active for sales.
        $options = $this->service->searchProductOptions([
            'warehouse_id' => 1,
        ]);

        $variantIds = collect($options)->pluck('product_variant_id')->all();

        // Non-sales item 30 must be present
        $this->assertContains(30, $variantIds);
        // Branch 1 active sales item 20 must be present
        $this->assertContains(20, $variantIds);
        // Sales item 10 is NOT assigned to Branch 1, so it must NOT be present
        $this->assertNotContains(10, $variantIds);

        // Check stock quantity
        $item30 = collect($options)->firstWhere('product_variant_id', 30);
        $this->assertEquals(100.0, $item30['on_hand_stock']);
        $this->assertFalse($item30['can_be_sold']);
    }

    public function test_returns_empty_when_warehouse_and_branch_are_mismatched(): void
    {
        // Warehouse 1 is linked to Branch 1, NOT Branch 2
        $options = $this->service->searchProductOptions([
            'warehouse_id' => 1,
            'branch_id' => 2,
        ]);

        // Mismatched warehouse and branch must return empty array
        $this->assertEmpty($options);
    }

    public function test_branch_filter_includes_non_sales_and_branch_assortment(): void
    {
        // Branch 1 has assortment configured (only variant 20 is active for sale)
        $options = $this->service->searchProductOptions([
            'warehouse_id' => 1,
            'branch_id' => 1,
        ]);

        $variantIds = collect($options)->pluck('product_variant_id')->all();

        // Non-sales variant 30 MUST be present
        $this->assertContains(30, $variantIds);
        // Branch 1 active sales variant 20 MUST be present
        $this->assertContains(20, $variantIds);
        // Variant 10 (sales product not assigned to Branch 1 assortment) MUST NOT be present
        $this->assertNotContains(10, $variantIds);
    }

    public function test_branch_with_no_active_products_returns_only_non_sales(): void
    {
        // Branch 2 is linked to Warehouse 2, but Branch 2 has 0 active sales products
        $options = $this->service->searchProductOptions([
            'warehouse_id' => 2,
            'branch_id' => 2,
        ]);

        $variantIds = collect($options)->pluck('product_variant_id')->all();

        // Non-sales variant 30 MUST be present
        $this->assertContains(30, $variantIds);
        // Sales variants MUST NOT be present
        $this->assertNotContains(10, $variantIds);
        $this->assertNotContains(20, $variantIds);
    }

    public function test_exclude_variant_ids_filters_out_selected_products(): void
    {
        $options = $this->service->searchProductOptions([
            'warehouse_id' => 3,
            'exclude_variant_ids' => [10, 30],
        ]);

        $variantIds = collect($options)->pluck('product_variant_id')->all();

        $this->assertNotContains(10, $variantIds);
        $this->assertNotContains(30, $variantIds);
        $this->assertContains(20, $variantIds);
    }

    public function test_duplicate_product_selection_is_rejected_on_prepare_items(): void
    {
        $this->expectException(ValidationException::class);

        // Simulate creating a PR with the same product variant on 2 lines
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('prepareItems');
        $method->setAccessible(true);

        $method->invoke($this->service, [
            [
                'product_variant_id' => 10,
                'unit_id' => 1,
                'unit_quantity' => 5,
            ],
            [
                'product_variant_id' => 10,
                'unit_id' => 1,
                'unit_quantity' => 2,
            ],
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'branch_active_products', 'warehouse_stocks', 'product_supplier', 'product_unit_conversions',
            'product_variant_units', 'product_variants', 'products', 'units', 'suppliers',
            'branch_warehouses', 'warehouses', 'branches', 'merchants',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('merchants', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->json('metadata')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->string('code')->nullable(); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->string('code')->nullable(); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('branch_warehouses', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('warehouse_id'); $table->boolean('is_active');
        });
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->string('code')->nullable(); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->string('type')->default('product'); $table->boolean('track_stock')->default(true); $table->boolean('can_be_sold')->default(true); $table->boolean('is_active')->default(true); $table->softDeletes();
        });
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('product_id'); $table->string('name')->nullable(); $table->string('sku'); $table->boolean('is_active')->default(true); $table->softDeletes();
        });
        Schema::create('units', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id')->nullable(); $table->string('name'); $table->string('code'); $table->boolean('is_active')->default(true); $table->softDeletes();
        });
        Schema::create('product_variant_units', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('product_variant_id'); $table->unsignedBigInteger('unit_id'); $table->decimal('cost', 18, 4)->nullable(); $table->boolean('is_active')->default(true); $table->softDeletes();
        });
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('product_id'); $table->unsignedBigInteger('unit_id'); $table->decimal('conversion_value', 18, 6)->default(1); $table->boolean('is_base')->default(false);
        });
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('warehouse_id'); $table->unsignedBigInteger('product_variant_id'); $table->decimal('quantity', 18, 4); $table->decimal('avg_cost', 18, 4)->default(0); $table->timestamps();
        });
        Schema::create('product_supplier', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('product_id'); $table->unsignedBigInteger('supplier_id'); $table->timestamps();
        });
        Schema::create('branch_active_products', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('product_variant_id'); $table->string('source'); $table->timestamp('resolved_at'); $table->timestamps();
        });
    }
}

