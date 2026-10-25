<?php

namespace Tests\Feature\Inventory;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Modules\Core\Helpers\MerchantContext;
use Modules\Inventory\Services\DocumentNumberService;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Merchant\Models\Merchant;
use Tests\TestCase;

class StockAdjustmentPostingTest extends TestCase
{
    private Merchant $merchant;
    private int $warehouseId;
    private int $variantId;
    private int $unitId;

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
            'name' => 'Merchant A',
            'metadata' => json_encode(['inventory' => ['allow_negative_stock' => false]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->merchant = Merchant::query()->findOrFail(1);
        MerchantContext::set($this->merchant);

        DB::table('branches')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Cabang A', 'is_active' => true]);
        DB::table('warehouses')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Gudang A', 'is_active' => true]);
        DB::table('branch_warehouses')->insert(['merchant_id' => 1, 'branch_id' => 1, 'warehouse_id' => 1, 'is_active' => true]);
        DB::table('products')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Produk A', 'type' => 'product', 'track_stock' => true, 'is_active' => true]);
        DB::table('product_variants')->insert(['id' => 1, 'merchant_id' => 1, 'product_id' => 1, 'name' => 'Varian A', 'sku' => 'SKU-A', 'is_active' => true]);
        DB::table('units')->insert(['id' => 1, 'merchant_id' => null, 'name' => 'Pcs', 'code' => 'PCS', 'is_active' => true]);
        DB::table('product_variant_units')->insert([
            'merchant_id' => 1, 'product_variant_id' => 1, 'unit_id' => 1,
            'conversion_value' => 1, 'is_base' => true, 'cost' => 10, 'is_active' => true,
        ]);
        DB::table('product_unit_conversions')->insert([
            ['merchant_id' => 1, 'product_id' => 1, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true],
        ]);

        $this->warehouseId = 1;
        $this->variantId = 1;
        $this->unitId = 1;
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_posting_updates_balance_and_writes_correct_before_after_ledger_rows(): void
    {
        $this->seedStock(10);
        $adjustment = $this->createAdjustment([2, -3]);

        app(StockAdjustmentService::class)->post($adjustment['id']);

        $this->assertSame(9.0, (float) DB::table('warehouse_stocks')->value('quantity'));
        $mutations = DB::table('stock_mutations')->orderBy('id')->get();
        $this->assertCount(2, $mutations);
        $this->assertSame([10.0, 12.0], [(float) $mutations[0]->quantity_before, (float) $mutations[0]->quantity_after]);
        $this->assertSame([12.0, 9.0], [(float) $mutations[1]->quantity_before, (float) $mutations[1]->quantity_after]);
        $this->assertSame([(float) 2, (float) -3], [(float) $mutations[0]->quantity, (float) $mutations[1]->quantity]);
    }

    public function test_void_writes_reverse_mutation_and_restores_the_balance(): void
    {
        $this->seedStock(10);
        $adjustment = $this->createAdjustment([4]);
        $service = app(StockAdjustmentService::class);
        $service->post($adjustment['id']);
        $service->void($adjustment['id'], 'Input salah');

        $this->assertSame(10.0, (float) DB::table('warehouse_stocks')->value('quantity'));
        $mutations = DB::table('stock_mutations')->orderBy('id')->get();
        $this->assertCount(2, $mutations);
        $this->assertSame([4.0, -4.0], [(float) $mutations[0]->quantity, (float) $mutations[1]->quantity]);
        $this->assertSame([10.0, 14.0], [(float) $mutations[0]->quantity_before, (float) $mutations[0]->quantity_after]);
        $this->assertSame([14.0, 10.0], [(float) $mutations[1]->quantity_before, (float) $mutations[1]->quantity_after]);
        $this->assertSame('stock_adjustment_void', $mutations[1]->mutation_type);
    }

    public function test_adjustment_uses_the_product_level_unit_conversion(): void
    {
        DB::table('units')->insert(['id' => 2, 'merchant_id' => null, 'name' => 'Dus', 'code' => 'DUS', 'is_active' => true]);
        DB::table('product_variant_units')->insert([
            'merchant_id' => 1, 'product_variant_id' => 1, 'unit_id' => 2,
            'conversion_value' => 5, 'is_base' => false, 'cost' => 50, 'is_active' => true,
        ]);
        DB::table('product_unit_conversions')->insert([
            'merchant_id' => 1, 'product_id' => 1, 'unit_id' => 2, 'conversion_value' => 12, 'is_base' => false,
        ]);

        $adjustment = app(StockAdjustmentService::class)->create([
            'warehouse_id' => $this->warehouseId,
            'adjustment_date' => now()->toDateString(),
            'reason' => 'found',
            'items' => [[
                'product_variant_id' => $this->variantId,
                'unit_id' => 2,
                'unit_quantity' => 3,
                'unit_cost' => null,
                'notes' => null,
            ]],
        ]);

        $item = DB::table('stock_adjustment_items')->where('stock_adjustment_id', $adjustment['id'])->first();
        $this->assertSame(12.0, (float) $item->conversion_rate);
        $this->assertSame(36.0, (float) $item->base_quantity);
    }

    public function test_negative_stock_rejection_rolls_back_all_items_and_document_status(): void
    {
        $this->seedStock(3);
        $adjustment = $this->createAdjustment([4, -10]);

        try {
            app(StockAdjustmentService::class)->post($adjustment['id']);
            $this->fail('Posting should reject a negative resulting balance.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame(3.0, (float) DB::table('warehouse_stocks')->value('quantity'));
            $this->assertSame(0, DB::table('stock_mutations')->count());
            $this->assertSame('draft', DB::table('stock_adjustments')->where('id', $adjustment['id'])->value('status'));
        }
    }

    public function test_merchant_cannot_read_or_post_another_merchants_adjustment(): void
    {
        $this->seedStock(5);
        $adjustment = $this->createAdjustment([5]);
        DB::table('merchants')->insert([
            'id' => 2, 'name' => 'Merchant B', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_adjustments')->where('id', $adjustment['id'])->update(['merchant_id' => 2]);
        DB::table('stock_adjustment_items')->where('stock_adjustment_id', $adjustment['id'])->update(['merchant_id' => 2]);

        try {
            app(StockAdjustmentService::class)->findForCurrentMerchant($adjustment['id']);
            $this->fail('Merchant A should not read Merchant B adjustment.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertDatabaseHas('stock_adjustments', ['id' => $adjustment['id'], 'status' => 'draft']);
        }

        try {
            app(StockAdjustmentService::class)->post($adjustment['id']);
            $this->fail('Merchant A should not post Merchant B adjustment.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(0, DB::table('stock_mutations')->count());
            $this->assertSame(5.0, (float) DB::table('warehouse_stocks')->value('quantity'));
        }
    }

    public function test_document_number_sequence_advances_and_resets_each_day(): void
    {
        Carbon::setTestNow('2026-10-23 10:00:00');
        $numberService = app(DocumentNumberService::class);
        $this->assertSame('ADJ-20261023-000001', $numberService->nextStockAdjustmentNumber());
        DB::table('stock_adjustments')->insert($this->adjustmentRow('ADJ-20261023-000001'));
        $this->assertSame('ADJ-20261023-000002', $numberService->nextStockAdjustmentNumber());

        Carbon::setTestNow('2026-10-24 10:00:00');
        $this->assertSame('ADJ-20261024-000001', $numberService->nextStockAdjustmentNumber());
    }

    public function test_lock_for_update_is_not_effective_with_the_sqlite_test_driver(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite is configured for the automated suite; use a multi-connection MySQL/PostgreSQL integration test to verify concurrent row locking.');
        }

        $sql = DB::table('warehouse_stocks')->where('id', 1)->lockForUpdate()->toSql();
        $this->assertStringNotContainsString('for update', strtolower($sql));
        $this->markTestSkipped('SQLite ignores SELECT FOR UPDATE, so the two-posting concurrency scenario cannot be proven by this test suite.');
    }

    private function createAdjustment(array $quantities): array
    {
        $items = array_map(fn (float|int $quantity) => [
            'product_variant_id' => $this->variantId,
            'unit_id' => $this->unitId,
            'unit_quantity' => $quantity,
            'unit_cost' => null,
            'notes' => null,
        ], $quantities);

        return app(StockAdjustmentService::class)->create([
            'warehouse_id' => $this->warehouseId,
            'adjustment_date' => '2026-10-23',
            'reason' => 'correction',
            'notes' => null,
            'items' => $items,
        ]);
    }

    private function seedStock(float $quantity): void
    {
        DB::table('warehouse_stocks')->insert([
            'merchant_id' => $this->merchant->id,
            'warehouse_id' => $this->warehouseId,
            'product_variant_id' => $this->variantId,
            'quantity' => $quantity,
            'avg_cost' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function adjustmentRow(string $number): array
    {
        return [
            'merchant_id' => $this->merchant->id,
            'document_number' => $number,
            'warehouse_id' => $this->warehouseId,
            'adjustment_date' => '2026-10-23',
            'reason' => 'correction',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createSchema(): void
    {
        foreach (['stock_adjustment_items', 'stock_adjustments', 'stock_mutations', 'warehouse_stocks', 'product_variant_units', 'product_unit_conversions', 'product_variants', 'products', 'units', 'branch_warehouses', 'warehouses', 'branches', 'merchants'] as $table) {
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
        Schema::create('branch_warehouses', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('branch_id'); $table->unsignedBigInteger('warehouse_id'); $table->boolean('is_active');
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
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('product_id'); $table->unsignedBigInteger('unit_id'); $table->decimal('conversion_value', 18, 6); $table->boolean('is_base');
        });
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('warehouse_id'); $table->unsignedBigInteger('product_variant_id'); $table->decimal('quantity', 18, 4); $table->decimal('avg_cost', 18, 4); $table->timestamp('last_mutated_at')->nullable(); $table->timestamps(); $table->unique(['warehouse_id', 'product_variant_id']);
        });
        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('warehouse_id'); $table->unsignedBigInteger('product_variant_id'); $table->string('mutation_type'); $table->decimal('quantity', 18, 4); $table->decimal('quantity_before', 18, 4); $table->decimal('quantity_after', 18, 4); $table->decimal('unit_cost', 18, 4)->default(0); $table->decimal('total_cost', 18, 4)->default(0); $table->decimal('avg_cost_after', 18, 4)->default(0); $table->string('reference_type')->nullable(); $table->unsignedBigInteger('reference_id')->nullable(); $table->string('reference_number')->nullable(); $table->text('notes')->nullable(); $table->timestamp('mutated_at'); $table->unsignedBigInteger('created_by')->nullable(); $table->timestamp('created_at');
        });
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('document_number'); $table->unsignedBigInteger('warehouse_id'); $table->date('adjustment_date'); $table->string('reason'); $table->string('status')->default('draft'); $table->text('notes')->nullable(); $table->timestamp('posted_at')->nullable(); $table->unsignedBigInteger('posted_by')->nullable(); $table->timestamp('voided_at')->nullable(); $table->unsignedBigInteger('voided_by')->nullable(); $table->string('void_reason')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->unsignedBigInteger('updated_by')->nullable(); $table->timestamps(); $table->softDeletes(); $table->unique(['merchant_id', 'document_number']);
        });
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('stock_adjustment_id'); $table->unsignedBigInteger('product_variant_id'); $table->unsignedBigInteger('unit_id'); $table->decimal('unit_quantity', 18, 4); $table->decimal('conversion_rate', 18, 6); $table->decimal('base_quantity', 18, 4); $table->decimal('unit_cost', 18, 4)->nullable(); $table->string('notes')->nullable(); $table->timestamps();
        });
    }
}
