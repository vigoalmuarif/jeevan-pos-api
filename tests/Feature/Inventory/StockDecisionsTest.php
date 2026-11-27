<?php

namespace Tests\Feature\Inventory;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MerchantContext;
use Modules\Inventory\Services\StockService;
use Modules\Merchant\Models\Merchant;
use Tests\TestCase;

class StockDecisionsTest extends TestCase
{
    private Merchant $merchant;
    private int $warehouseId = 1;
    private int $variantId = 1;
    private int $unitId = 1;

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
            'name' => 'Merchant Dec',
            'metadata' => json_encode(['inventory' => ['allow_negative_stock' => false]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->merchant = Merchant::query()->findOrFail(1);
        MerchantContext::set($this->merchant);

        DB::table('branches')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Cabang 1', 'is_active' => true]);
        DB::table('warehouses')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Gudang 1', 'is_active' => true]);
        DB::table('products')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Produk 1', 'type' => 'product', 'track_stock' => true, 'is_active' => true]);
        DB::table('product_variants')->insert(['id' => 1, 'merchant_id' => 1, 'product_id' => 1, 'name' => 'Varian 1', 'sku' => 'SKU-DEC-1', 'is_active' => true]);
        DB::table('units')->insert(['id' => 1, 'merchant_id' => null, 'name' => 'Pcs', 'code' => 'PCS', 'is_active' => true]);
        DB::table('product_variant_units')->insert([
            'merchant_id' => 1,
            'product_variant_id' => 1,
            'unit_id' => 1,
            'conversion_value' => 1,
            'is_base' => true,
            'cost' => 75.00,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    public function test_dec_07_record_mutation_throws_when_outside_transaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('recordMutation harus selalu dijalankan di dalam DB::transaction().');

        $stockService = app(StockService::class);
        $stockService->recordMutation(
            warehouseId: $this->warehouseId,
            productVariantId: $this->variantId,
            baseQuantity: 5,
            mutationType: 'opening_stock',
            incomingUnitCost: 100,
        );
    }

    public function test_dec_07_record_mutation_succeeds_inside_transaction(): void
    {
        $stockService = app(StockService::class);

        $mutation = DB::transaction(function () use ($stockService) {
            return $stockService->recordMutation(
                warehouseId: $this->warehouseId,
                productVariantId: $this->variantId,
                baseQuantity: 10,
                mutationType: 'opening_stock',
                incomingUnitCost: 100,
            );
        });

        $this->assertSame(10.0, (float) $mutation->quantity);
        $this->assertSame(10.0, (float) DB::table('warehouse_stocks')->value('quantity'));
        $this->assertSame(100.0, (float) DB::table('warehouse_stocks')->value('avg_cost'));
    }

    public function test_dec_08_reversal_unit_cost_restores_moving_average_properly(): void
    {
        $stockService = app(StockService::class);

        // 1. Saldo awal: 10 @ 100 -> total 1000
        DB::transaction(function () use ($stockService) {
            $stockService->recordMutation(
                warehouseId: $this->warehouseId,
                productVariantId: $this->variantId,
                baseQuantity: 10,
                mutationType: 'opening_stock',
                incomingUnitCost: 100,
            );
        });

        // 2. Pembelian masuk: 10 @ 200 -> total 2000 => saldo 20 @ 150
        DB::transaction(function () use ($stockService) {
            $stockService->recordMutation(
                warehouseId: $this->warehouseId,
                productVariantId: $this->variantId,
                baseQuantity: 10,
                mutationType: 'purchase_receipt',
                incomingUnitCost: 200,
            );
        });

        $stockBeforeVoid = DB::table('warehouse_stocks')->first();
        $this->assertSame(20.0, (float) $stockBeforeVoid->quantity);
        $this->assertSame(150.0, (float) $stockBeforeVoid->avg_cost);

        // 3. Void penerimaan masuk: 10 dibatalkan dengan reversedUnitCost = 200
        DB::transaction(function () use ($stockService) {
            $stockService->recordMutation(
                warehouseId: $this->warehouseId,
                productVariantId: $this->variantId,
                baseQuantity: -10,
                mutationType: 'purchase_receipt_void',
                reversedUnitCost: 200,
            );
        });

        // Saldo kembali 10 @ 100
        $stockAfterVoid = DB::table('warehouse_stocks')->first();
        $this->assertSame(10.0, (float) $stockAfterVoid->quantity);
        $this->assertSame(100.0, (float) $stockAfterVoid->avg_cost);
    }

    public function test_dec_09_negative_stock_uses_master_cost_fallback(): void
    {
        // Izinkan stok negatif untuk merchant ini
        DB::table('merchants')->where('id', 1)->update([
            'metadata' => json_encode(['inventory' => ['allow_negative_stock' => true]]),
        ]);
        MerchantContext::set(Merchant::query()->findOrFail(1));

        $stockService = app(StockService::class);

        // Mutasi keluar 5 pcs saat stok masih 0
        $mutation = DB::transaction(function () use ($stockService) {
            return $stockService->recordMutation(
                warehouseId: $this->warehouseId,
                productVariantId: $this->variantId,
                baseQuantity: -5,
                mutationType: 'pos_sale',
            );
        });

        // unit_cost tidak boleh 0, harus mengambil fallback dari product_variant_units.cost (75.00)
        $this->assertSame(75.0, (float) $mutation->unit_cost);

        $stock = DB::table('warehouse_stocks')->first();
        $this->assertSame(-5.0, (float) $stock->quantity);
        $this->assertSame(75.0, (float) $stock->avg_cost);

        $mutationRow = DB::table('stock_mutations')->where('id', $mutation->id)->first();
        $this->assertSame(75.0, (float) $mutationRow->unit_cost);
        $this->assertSame(-375.0, (float) $mutationRow->total_cost);
    }

    private function createSchema(): void
    {
        foreach (['stock_mutations', 'warehouse_stocks', 'product_variant_units', 'product_variants', 'products', 'units', 'warehouses', 'branches', 'merchants'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('merchants', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->json('metadata')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->string('type'); $table->boolean('track_stock'); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('product_id'); $table->string('name')->nullable(); $table->string('sku'); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('units', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id')->nullable(); $table->string('name'); $table->string('code'); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('product_variant_units', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('product_variant_id'); $table->unsignedBigInteger('unit_id'); $table->decimal('conversion_value', 18, 6); $table->boolean('is_base'); $table->decimal('cost', 18, 4)->nullable(); $table->boolean('is_active'); $table->softDeletes();
        });
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('warehouse_id'); $table->unsignedBigInteger('product_variant_id'); $table->decimal('quantity', 18, 4); $table->decimal('avg_cost', 18, 4); $table->timestamp('last_mutated_at')->nullable(); $table->timestamps(); $table->unique(['warehouse_id', 'product_variant_id']);
        });
        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('warehouse_id'); $table->unsignedBigInteger('product_variant_id'); $table->string('mutation_type'); $table->decimal('quantity', 18, 4); $table->decimal('quantity_before', 18, 4); $table->decimal('quantity_after', 18, 4); $table->decimal('unit_cost', 18, 4)->default(0); $table->decimal('total_cost', 18, 4)->default(0); $table->decimal('avg_cost_after', 18, 4)->default(0); $table->string('reference_type')->nullable(); $table->unsignedBigInteger('reference_id')->nullable(); $table->string('reference_number')->nullable(); $table->text('notes')->nullable(); $table->timestamp('mutated_at'); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamp('created_at');
        });
    }
}

