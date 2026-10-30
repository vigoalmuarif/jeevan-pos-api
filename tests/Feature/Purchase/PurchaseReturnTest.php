<?php

namespace Tests\Feature\Purchase;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MerchantContext;
use Modules\Inventory\Services\StockService;
use Modules\Merchant\Models\Merchant;
use Modules\Purchase\Enums\DocumentStatus;
use Modules\Purchase\Enums\ReturnReason;
use Modules\Purchase\Enums\SettlementMethod;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Services\PurchaseReturnService;
use Tests\TestCase;

class PurchaseReturnTest extends TestCase
{
    private Merchant $merchant;
    private int $warehouseId = 1;
    private int $supplierId = 1;
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
            'name' => 'Merchant Test',
            'metadata' => json_encode(['inventory' => ['allow_negative_stock' => false]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->merchant = Merchant::query()->findOrFail(1);
        MerchantContext::set($this->merchant);

        DB::table('branches')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Cabang 1', 'is_active' => true]);
        DB::table('warehouses')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Gudang 1', 'is_active' => true]);
        DB::table('suppliers')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Supplier A', 'code' => 'SUP-A', 'is_active' => true]);
        DB::table('products')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Produk 1', 'type' => 'product', 'track_stock' => true, 'is_active' => true]);
        DB::table('product_variants')->insert(['id' => 1, 'merchant_id' => 1, 'product_id' => 1, 'name' => 'Varian 1', 'sku' => 'SKU-RET-1', 'is_active' => true]);
        DB::table('units')->insert(['id' => 1, 'merchant_id' => null, 'name' => 'Pcs', 'code' => 'PCS', 'is_active' => true]);
        DB::table('product_variant_units')->insert([
            'merchant_id' => 1,
            'product_variant_id' => 1,
            'unit_id' => 1,
            'conversion_value' => 1,
            'is_base' => true,
            'cost' => 100.00,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    public function test_can_create_and_post_purchase_return(): void
    {
        // 1. Setup posted Goods Receipt dengan 10 pcs @ 150
        $receiptId = DB::table('goods_receipts')->insertGetId([
            'merchant_id' => 1,
            'branch_id' => 1,
            'supplier_id' => 1,
            'warehouse_id' => 1,
            'document_number' => 'GR-202610-0001',
            'receipt_date' => '2026-10-25',
            'status' => 'posted',
            'grand_total' => 1500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receiptItemId = DB::table('goods_receipt_items')->insertGetId([
            'merchant_id' => 1,
            'goods_receipt_id' => $receiptId,
            'product_variant_id' => 1,
            'unit_id' => 1,
            'unit_quantity' => 10,
            'conversion_rate' => 1,
            'base_quantity' => 10,
            'unit_price' => 150,
            'unit_cost' => 150,
            'subtotal' => 1500,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 1500,
            'cost_amount' => 1500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulasikan stok fisik di gudang sebanyak 10 pcs @ 150
        app(StockService::class);
        DB::transaction(function () {
            app(StockService::class)->recordMutation(
                warehouseId: 1,
                productVariantId: 1,
                baseQuantity: 10,
                mutationType: 'purchase_receipt',
                incomingUnitCost: 150,
            );
        });

        $this->assertSame(10.0, (float) DB::table('warehouse_stocks')->value('quantity'));
        $this->assertSame(150.0, (float) DB::table('warehouse_stocks')->value('avg_cost'));

        // 2. Buat Draft Retur Pembelian untuk 3 pcs rusak
        $service = app(PurchaseReturnService::class);
        $return = $service->create(
            [
                'goods_receipt_id' => $receiptId,
                'branch_id' => 1,
                'return_date' => '2026-10-26',
                'settlement_method' => SettlementMethod::InvoiceDeduction->value,
                'reason_code' => ReturnReason::Damaged->value,
                'notes' => '3 barang pecah',
            ],
            [
                [
                    'goods_receipt_item_id' => $receiptItemId,
                    'unit_quantity' => 3,
                    'reason' => 'Pecah saat unboxing',
                ],
            ]
        );

        $this->assertSame(DocumentStatus::Draft, $return->status);
        $this->assertSame(450.0, (float) $return->grand_total);
        $this->assertCount(1, $return->items);

        // 3. Posting Retur
        $postedReturn = $service->post($return->id);
        $this->assertSame(DocumentStatus::Posted, $postedReturn->status);

        // Stok berkurang dari 10 menjadi 7
        $this->assertSame(7.0, (float) DB::table('warehouse_stocks')->value('quantity'));
        $this->assertSame(150.0, (float) DB::table('warehouse_stocks')->value('avg_cost'));

        // Cek log mutasi stok
        $mutation = DB::table('stock_mutations')->orderByDesc('id')->first();
        $this->assertSame('purchase_return', $mutation->mutation_type);
        $this->assertSame(-3.0, (float) $mutation->quantity);
        $this->assertSame(150.0, (float) $mutation->unit_cost);
        $this->assertSame(-450.0, (float) $mutation->total_cost);

        // 4. Void Retur
        $voidedReturn = $service->void($postedReturn->id, 'Supplier kirim kurir ganti unit baru');
        $this->assertSame(DocumentStatus::Voided, $voidedReturn->status);

        // Stok kembali menjadi 10
        $this->assertSame(10.0, (float) DB::table('warehouse_stocks')->value('quantity'));
        $this->assertSame(150.0, (float) DB::table('warehouse_stocks')->value('avg_cost'));

        $reversalMutation = DB::table('stock_mutations')->orderByDesc('id')->first();
        $this->assertSame('purchase_return_void', $reversalMutation->mutation_type);
        $this->assertSame(3.0, (float) $reversalMutation->quantity);
    }

    public function test_cannot_return_more_than_received_quantity(): void
    {
        $receiptId = DB::table('goods_receipts')->insertGetId([
            'merchant_id' => 1,
            'supplier_id' => 1,
            'warehouse_id' => 1,
            'document_number' => 'GR-202610-0002',
            'receipt_date' => '2026-10-25',
            'status' => 'posted',
            'grand_total' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receiptItemId = DB::table('goods_receipt_items')->insertGetId([
            'merchant_id' => 1,
            'goods_receipt_id' => $receiptId,
            'product_variant_id' => 1,
            'unit_id' => 1,
            'unit_quantity' => 5,
            'conversion_rate' => 1,
            'base_quantity' => 5,
            'unit_price' => 100,
            'unit_cost' => 100,
            'subtotal' => 500,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 500,
            'cost_amount' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(PurchaseReturnService::class)->create(
            [
                'goods_receipt_id' => $receiptId,
                'return_date' => '2026-10-26',
                'settlement_method' => SettlementMethod::InvoiceDeduction->value,
                'reason_code' => ReturnReason::Damaged->value,
            ],
            [
                [
                    'goods_receipt_item_id' => $receiptItemId,
                    'unit_quantity' => 6, // Lebih besar dari 5!
                ],
            ]
        );
    }

    private function createSchema(): void
    {
        foreach ([
            'purchase_return_items', 'purchase_returns',
            'purchase_invoice_items', 'purchase_invoices',
            'goods_receipt_items', 'goods_receipts',
            'stock_mutations', 'warehouse_stocks',
            'product_variant_units', 'product_variants', 'products', 'units',
            'suppliers', 'warehouses', 'branches', 'merchants',
        ] as $table) {
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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->string('name'); $table->string('code')->nullable(); $table->integer('payment_term_days')->default(0); $table->string('phone')->nullable(); $table->string('email')->nullable(); $table->boolean('is_active'); $table->softDeletes();
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
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('branch_id')->nullable(); $table->unsignedBigInteger('supplier_id'); $table->unsignedBigInteger('warehouse_id'); $table->unsignedBigInteger('purchase_order_id')->nullable(); $table->unsignedBigInteger('purchase_invoice_id')->nullable(); $table->string('document_number'); $table->date('receipt_date'); $table->string('delivery_note_number')->nullable(); $table->string('status')->default('draft'); $table->decimal('grand_total', 18, 4)->default(0); $table->text('notes')->nullable(); $table->timestamp('posted_at')->nullable(); $table->unsignedBigInteger('posted_by')->nullable(); $table->timestamp('voided_at')->nullable(); $table->unsignedBigInteger('voided_by')->nullable(); $table->string('void_reason')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('goods_receipt_id'); $table->unsignedBigInteger('product_variant_id'); $table->unsignedBigInteger('unit_id'); $table->decimal('unit_quantity', 18, 4); $table->decimal('conversion_rate', 18, 6)->default(1); $table->decimal('base_quantity', 18, 4); $table->decimal('unit_price', 18, 4); $table->decimal('unit_cost', 18, 4); $table->decimal('subtotal', 18, 4); $table->decimal('tax_rate', 5, 2)->default(0); $table->decimal('tax_amount', 18, 4)->default(0); $table->decimal('line_total', 18, 4); $table->decimal('cost_amount', 18, 4); $table->timestamps();
        });
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('branch_id')->nullable(); $table->unsignedBigInteger('supplier_id'); $table->unsignedBigInteger('warehouse_id'); $table->unsignedBigInteger('goods_receipt_id'); $table->unsignedBigInteger('purchase_invoice_id')->nullable(); $table->string('document_number'); $table->date('return_date'); $table->string('status')->default('draft'); $table->string('settlement_method')->default('invoice_deduction'); $table->string('reason_code')->default('damaged'); $table->decimal('subtotal', 18, 4)->default(0); $table->decimal('tax_amount', 18, 4)->default(0); $table->decimal('grand_total', 18, 4)->default(0); $table->text('notes')->nullable(); $table->timestamp('posted_at')->nullable(); $table->unsignedBigInteger('posted_by')->nullable(); $table->timestamp('voided_at')->nullable(); $table->unsignedBigInteger('voided_by')->nullable(); $table->string('void_reason')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('merchant_id'); $table->unsignedBigInteger('purchase_return_id'); $table->unsignedBigInteger('goods_receipt_item_id'); $table->unsignedBigInteger('product_variant_id'); $table->unsignedBigInteger('unit_id'); $table->decimal('unit_quantity', 18, 4); $table->decimal('conversion_rate', 18, 6)->default(1); $table->decimal('base_quantity', 18, 4); $table->decimal('unit_price', 18, 4); $table->decimal('unit_cost', 18, 4); $table->decimal('subtotal', 18, 4)->default(0); $table->decimal('tax_rate', 5, 2)->default(0); $table->decimal('tax_amount', 18, 4)->default(0); $table->decimal('line_total', 18, 4)->default(0); $table->string('reason')->nullable(); $table->timestamps();
        });
    }
}

