<?php

namespace Tests\Feature\Inventory;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Inventory\Enums\StockRequestStatus;
use Modules\Inventory\Models\StockRequest;
use Modules\Inventory\Services\StockRequestService;
use Modules\Merchant\Models\Merchant;
use Tests\TestCase;

class StockRequestTest extends TestCase
{
    private Merchant $merchant;
    private StockRequestService $service;

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
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Cabang Sudirman', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Cabang Dago', 'is_active' => true],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Gudang Cabang Sudirman', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Gudang Cabang Dago', 'is_active' => true],
            ['id' => 3, 'merchant_id' => 1, 'name' => 'Gudang Pusat Mandiri', 'is_active' => true],
        ]);
        DB::table('branch_warehouses')->insert([
            ['merchant_id' => 1, 'branch_id' => 1, 'warehouse_id' => 1, 'is_primary' => true, 'is_active' => true],
            ['merchant_id' => 1, 'branch_id' => 2, 'warehouse_id' => 2, 'is_primary' => true, 'is_active' => true],
        ]);

        // Units
        DB::table('units')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Pcs', 'code' => 'PCS', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Box', 'code' => 'BOX', 'is_active' => true],
        ]);

        // Products & Variants
        DB::table('products')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Kopi Robusta', 'type' => 'product', 'track_stock' => true, 'can_be_sold' => true, 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Susu Segar', 'type' => 'product', 'track_stock' => true, 'can_be_sold' => true, 'is_active' => true],
        ]);

        DB::table('product_variants')->insert([
            ['id' => 10, 'merchant_id' => 1, 'product_id' => 1, 'name' => '250gr', 'sku' => 'KOP-250', 'is_active' => true],
            ['id' => 20, 'merchant_id' => 1, 'product_id' => 2, 'name' => '1 Liter', 'sku' => 'SUS-1L', 'is_active' => true],
        ]);

        // Conversions & Costs
        DB::table('product_variant_units')->insert([
            ['merchant_id' => 1, 'product_variant_id' => 10, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'cost' => 10000, 'is_active' => true],
            ['merchant_id' => 1, 'product_variant_id' => 10, 'unit_id' => 2, 'conversion_value' => 12, 'is_base' => false, 'cost' => 115000, 'is_active' => true],
            ['merchant_id' => 1, 'product_variant_id' => 20, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'cost' => 18000, 'is_active' => true],
        ]);

        DB::table('product_unit_conversions')->insert([
            ['merchant_id' => 1, 'product_id' => 1, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true],
            ['merchant_id' => 1, 'product_id' => 1, 'unit_id' => 2, 'conversion_value' => 12, 'is_base' => false],
            ['merchant_id' => 1, 'product_id' => 2, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true],
        ]);

        // Thresholds in Warehouse 1 (Cabang Sudirman)
        DB::table('warehouse_stock_thresholds')->insert([
            [
                'merchant_id' => 1,
                'warehouse_id' => 1,
                'product_variant_id' => 10,
                'min_quantity' => 10.0000,
                'max_quantity' => 50.0000,
                'is_active' => true,
            ],
            [
                'merchant_id' => 1,
                'warehouse_id' => 1,
                'product_variant_id' => 20,
                'min_quantity' => 5.0000,
                'max_quantity' => 20.0000,
                'is_active' => true,
            ],
        ]);

        // Current stock in Warehouse 1: Variant 10 = 3 pcs (< 10 min), Variant 20 = 15 pcs (>= 5 min)
        DB::table('warehouse_stocks')->insert([
            ['merchant_id' => 1, 'warehouse_id' => 1, 'product_variant_id' => 10, 'quantity' => 3.0000, 'avg_cost' => 10000],
            ['merchant_id' => 1, 'warehouse_id' => 1, 'product_variant_id' => 20, 'quantity' => 15.0000, 'avg_cost' => 18000],
            // Central Warehouse (3) has plenty of stock
            ['merchant_id' => 1, 'warehouse_id' => 3, 'product_variant_id' => 10, 'quantity' => 500.0000, 'avg_cost' => 9500],
            ['merchant_id' => 1, 'warehouse_id' => 3, 'product_variant_id' => 20, 'quantity' => 300.0000, 'avg_cost' => 17000],
        ]);

        $this->service = app(StockRequestService::class);
    }

    public function test_can_create_and_find_stock_request_draft(): void
    {
        $request = $this->service->create([
            'branch_id' => 1,
            'destination_warehouse_id' => 1,
            'source_warehouse_id' => 3,
            'request_date' => '2026-11-09',
            'expected_date' => '2026-11-12',
            'notes' => 'Stok kopi menipis di cabang',
        ], [
            [
                'product_variant_id' => 10,
                'unit_id' => 2, // 2 Box = 24 pcs base
                'unit_quantity' => 2,
                'notes' => 'Perlu segera',
            ],
        ]);

        $this->assertNotNull($request->id);
        $this->assertEquals(StockRequestStatus::Draft, $request->status);
        $this->assertStringStartsWith('STR-', $request->document_number);
        $this->assertCount(1, $request->items);
        $this->assertEquals(24.0, (float) $request->items->first()->base_quantity);
    }

    public function test_fails_if_destination_warehouse_not_connected_to_branch(): void
    {
        $this->expectException(ValidationException::class);

        // Branch 1 is connected to Warehouse 1, NOT Warehouse 2
        $this->service->create([
            'branch_id' => 1,
            'destination_warehouse_id' => 2,
            'request_date' => '2026-11-09',
        ], [
            [
                'product_variant_id' => 10,
                'unit_id' => 1,
                'unit_quantity' => 5,
            ],
        ]);
    }

    public function test_full_lifecycle_submit_approve_and_convert_to_transfer(): void
    {
        $request = $this->service->create([
            'branch_id' => 1,
            'destination_warehouse_id' => 1,
            'source_warehouse_id' => 3,
            'request_date' => '2026-11-09',
        ], [
            [
                'product_variant_id' => 10,
                'unit_id' => 1,
                'unit_quantity' => 20,
            ],
        ]);

        // Submit
        $submitted = $this->service->submit($request->id);
        $this->assertEquals(StockRequestStatus::Submitted, $submitted->status);

        // Approve
        $approved = $this->service->approve($submitted->id);
        $this->assertEquals(StockRequestStatus::Approved, $approved->status);

        // Convert to Transfer (Pengiriman dari Pusat ke Cabang)
        $item = $approved->items->first();
        $transfer = $this->service->convertToTransfer($approved->id, [
            'from_warehouse_id' => 3,
            'transfer_date' => '2026-11-09',
            'items' => [
                [
                    'stock_request_item_id' => $item->id,
                    'unit_quantity' => 20,
                ],
            ],
        ]);

        $this->assertNotNull($transfer->id);
        $this->assertEquals($approved->id, $transfer->stock_request_id);
        $this->assertEquals(3, $transfer->from_warehouse_id);
        $this->assertEquals(1, $transfer->to_warehouse_id);

        $refreshed = $this->service->findForCurrentMerchant($approved->id);
        $this->assertEquals(StockRequestStatus::Transferred, $refreshed->status);
    }

    public function test_partial_transfer_updates_status_to_partial_transferred(): void
    {
        $request = $this->service->create([
            'branch_id' => 1,
            'destination_warehouse_id' => 1,
            'source_warehouse_id' => 3,
            'request_date' => '2026-11-09',
        ], [
            [
                'product_variant_id' => 10,
                'unit_id' => 1,
                'unit_quantity' => 50,
            ],
        ]);

        $this->service->submit($request->id);
        $approved = $this->service->approve($request->id);

        $item = $approved->items->first();
        // Kirim 20 dari 50
        $this->service->convertToTransfer($approved->id, [
            'from_warehouse_id' => 3,
            'items' => [
                [
                    'stock_request_item_id' => $item->id,
                    'unit_quantity' => 20,
                ],
            ],
        ]);

        $refreshed = $this->service->findForCurrentMerchant($approved->id);
        $this->assertEquals(StockRequestStatus::PartialTransferred, $refreshed->status);

        // Kirim sisanya 30
        $this->service->convertToTransfer($approved->id, [
            'from_warehouse_id' => 3,
            'items' => [
                [
                    'stock_request_item_id' => $item->id,
                    'unit_quantity' => 30,
                ],
            ],
        ]);

        $refreshed = $this->service->findForCurrentMerchant($approved->id);
        $this->assertEquals(StockRequestStatus::Transferred, $refreshed->status);
    }

    public function test_can_reject_submitted_stock_request(): void
    {
        $request = $this->service->create([
            'branch_id' => 1,
            'destination_warehouse_id' => 1,
            'request_date' => '2026-11-09',
        ], [
            [
                'product_variant_id' => 10,
                'unit_id' => 1,
                'unit_quantity' => 10,
            ],
        ]);

        $this->service->submit($request->id);
        $rejected = $this->service->reject($request->id, 'Stok pusat tidak mencukupi');

        $this->assertEquals(StockRequestStatus::Rejected, $rejected->status);
        $this->assertEquals('Stok pusat tidak mencukupi', $rejected->reject_reason);
    }

    public function test_low_stock_suggestions_returns_only_items_below_min_threshold(): void
    {
        // In setUp:
        // Variant 10: current = 3, min = 10, max = 50 -> should be suggested! (suggested = 47)
        // Variant 20: current = 15, min = 5, max = 20 -> NOT suggested (above min)
        $suggestions = $this->service->getLowStockSuggestions(1, 1);

        $this->assertCount(1, $suggestions);
        $this->assertEquals(10, $suggestions[0]['product_variant_id']);
        $this->assertEquals(3.0, (float) $suggestions[0]['current_stock']);
        $this->assertEquals(10.0, (float) $suggestions[0]['min_quantity']);
        $this->assertEquals(50.0, (float) $suggestions[0]['max_quantity']);
        $this->assertEquals(47.0, (float) $suggestions[0]['suggested_quantity']);
    }

    public function test_merchant_isolation_cannot_access_other_merchant_request(): void
    {
        $request = $this->service->create([
            'branch_id' => 1,
            'destination_warehouse_id' => 1,
            'request_date' => '2026-11-09',
        ], [
            [
                'product_variant_id' => 10,
                'unit_id' => 1,
                'unit_quantity' => 10,
            ],
        ]);

        // Switch context to Merchant 2
        DB::table('merchants')->insert([
            'id' => 2,
            'name' => 'Merchant Lain',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $merchant2 = Merchant::query()->findOrFail(2);
        MerchantContext::set($merchant2);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->service->findForCurrentMerchant($request->id);
    }

    public function test_search_product_options_calculates_destination_and_source_stocks(): void
    {
        // Variant 10 ada di Warehouse 1 (tujuan), Variant 20 hanya ada di Warehouse 2
        DB::table('warehouse_stocks')->where('product_variant_id', 20)->delete();
        DB::table('warehouse_stocks')->insert([
            ['merchant_id' => 1, 'warehouse_id' => 2, 'product_variant_id' => 20, 'quantity' => 10, 'avg_cost' => 2000, 'created_at' => now(), 'updated_at' => now()],
            // Di Warehouse 3 (Pusat): Variant 10 ada stok 20
            ['merchant_id' => 1, 'warehouse_id' => 3, 'product_variant_id' => 10, 'quantity' => 20, 'avg_cost' => 1000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Cari dengan destination_warehouse_id = 1, source_warehouse_id = 3
        $results = $this->service->searchProductOptions([
            'destination_warehouse_id' => 1,
            'source_warehouse_id' => 3,
        ]);

        // Kedua varian aktif dapat dipilih oleh cabang
        $this->assertCount(2, $results);
        $variant10 = collect($results)->firstWhere('product_variant_id', 10);
        $variant20 = collect($results)->firstWhere('product_variant_id', 20);

        // Variant 10: stok cabang = 3, stok pusat = 20
        $this->assertEquals(3.0, $variant10['destination_stock']);
        $this->assertEquals(20.0, $variant10['source_stock']);
        $this->assertTrue($variant10['is_assigned_to_source']);
        $this->assertFalse($variant10['is_source_out_of_stock']);

        // Variant 20: stok cabang = 0, di warehouse 3 (pusat) belum ada
        $this->assertEquals(0.0, $variant20['destination_stock']);
        $this->assertFalse($variant20['is_assigned_to_source']);
        $this->assertTrue($variant20['is_source_out_of_stock']);
    }

    private function createSchema(): void
    {
        foreach ([
            'stock_transfers', 'stock_transfer_items', 'stock_request_items', 'stock_requests',
            'warehouse_stock_thresholds', 'warehouse_stocks', 'product_unit_conversions',
            'product_variant_units', 'product_variants', 'products', 'units',
            'branch_warehouses', 'warehouses', 'branches', 'merchants', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('merchants', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->json('metadata')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name')->default('User Test'); $table->string('email')->nullable(); $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->string('code')->nullable(); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->string('code')->nullable(); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('branch_warehouses', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('warehouse_id'); $table->boolean('is_primary')->default(false); $table->boolean('is_active')->default(true);
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
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('product_variant_id'); $table->unsignedBigInteger('unit_id'); $table->decimal('conversion_value', 18, 6)->default(1); $table->boolean('is_base')->default(false); $table->decimal('cost', 18, 4)->nullable(); $table->boolean('is_active')->default(true); $table->softDeletes();
        });
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('product_id'); $table->unsignedBigInteger('unit_id'); $table->decimal('conversion_value', 18, 6)->default(1); $table->boolean('is_base')->default(false);
        });
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('warehouse_id'); $table->unsignedBigInteger('product_variant_id'); $table->decimal('quantity', 18, 4); $table->decimal('avg_cost', 18, 4)->default(0); $table->timestamps();
        });
        Schema::create('warehouse_stock_thresholds', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('warehouse_id'); $table->unsignedBigInteger('product_variant_id'); $table->decimal('min_quantity', 18, 4); $table->decimal('max_quantity', 18, 4); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('document_number');
            $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('destination_warehouse_id'); $table->unsignedBigInteger('source_warehouse_id')->nullable();
            $table->date('request_date'); $table->date('expected_date')->nullable();
            $table->string('status', 25)->default('draft'); $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable(); $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('approved_at')->nullable(); $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('rejected_at')->nullable(); $table->unsignedBigInteger('rejected_by')->nullable(); $table->string('reject_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable(); $table->unsignedBigInteger('cancelled_by')->nullable(); $table->string('cancel_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('stock_request_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('stock_request_id');
            $table->unsignedBigInteger('product_variant_id'); $table->unsignedBigInteger('unit_id');
            $table->decimal('unit_quantity', 20, 4); $table->decimal('conversion_rate', 20, 6);
            $table->decimal('base_quantity', 20, 4); $table->decimal('approved_base_quantity', 20, 4)->nullable();
            $table->decimal('transferred_base_quantity', 20, 4)->default(0);
            $table->string('notes')->nullable(); $table->timestamps();
        });
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('document_number');
            $table->unsignedBigInteger('stock_request_id')->nullable();
            $table->unsignedBigInteger('from_warehouse_id'); $table->unsignedBigInteger('to_warehouse_id');
            $table->date('transfer_date'); $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable(); $table->text('receive_notes')->nullable();
            $table->timestamp('shipped_at')->nullable(); $table->unsignedBigInteger('shipped_by')->nullable();
            $table->timestamp('received_at')->nullable(); $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('cancelled_at')->nullable(); $table->unsignedBigInteger('cancelled_by')->nullable(); $table->string('cancel_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('stock_transfer_id');
            $table->unsignedBigInteger('product_variant_id'); $table->unsignedBigInteger('unit_id');
            $table->decimal('unit_quantity', 20, 4); $table->decimal('conversion_rate', 20, 6);
            $table->decimal('base_quantity', 20, 4); $table->decimal('received_base_quantity', 20, 4)->nullable();
            $table->decimal('variance_base_quantity', 20, 4)->default(0); $table->decimal('unit_cost', 20, 4)->nullable();
            $table->string('notes')->nullable(); $table->timestamps();
        });
    }
}
