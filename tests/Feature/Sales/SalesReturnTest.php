<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Inventory\Enums\MutationType;
use Modules\Inventory\Services\StockService;
use Modules\Merchant\Models\Merchant;
use Modules\Sales\Enums\SalesReturnStatus;
use Modules\Sales\Services\CashierShiftService;
use Modules\Sales\Services\SalesDocumentNumberService;
use Modules\Sales\Services\SalesReturnService;
use Tests\TestCase;

class SalesReturnTest extends TestCase
{
    private Merchant $merchantA;
    private Merchant $merchantB;
    private SalesReturnService $returnService;
    private CashierShiftService $shiftService;

    protected function setUp(): void
    {
        parent::setUp();
        MerchantContext::clear();

        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('These tests require the isolated in-memory SQLite database from phpunit.xml.');
        }

        $this->createSchema();

        DB::table('merchants')->insert([
            [
                'id' => 1,
                'name' => 'Merchant A',
                'metadata' => json_encode(['inventory' => ['allow_negative_stock' => false]]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Merchant B',
                'metadata' => json_encode(['inventory' => ['allow_negative_stock' => false]]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->merchantA = Merchant::query()->findOrFail(1);
        $this->merchantB = Merchant::query()->findOrFail(2);
        MerchantContext::set($this->merchantA);

        DB::table('users')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Kasir A', 'email' => 'kasir-a@test.id', 'password' => 'secret'],
            ['id' => 2, 'merchant_id' => 2, 'name' => 'Kasir B', 'email' => 'kasir-b@test.id', 'password' => 'secret'],
        ]);

        DB::table('warehouses')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Gudang A', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 2, 'name' => 'Gudang B', 'is_active' => true],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Cabang A', 'primary_warehouse_id' => 1, 'is_main' => true, 'is_active' => true],
            ['id' => 2, 'merchant_id' => 2, 'name' => 'Cabang B', 'primary_warehouse_id' => 2, 'is_main' => true, 'is_active' => true],
        ]);

        DB::table('products')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Kopi Susu', 'track_stock' => true, 'can_be_sold' => true, 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Roti Bakar', 'track_stock' => true, 'can_be_sold' => true, 'is_active' => true],
        ]);

        DB::table('units')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Cup', 'code' => 'CUP', 'is_active' => true],
        ]);

        DB::table('product_variants')->insert([
            ['id' => 1, 'merchant_id' => 1, 'product_id' => 1, 'name' => 'Reguler', 'sku' => 'KOP-1', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'product_id' => 2, 'name' => 'Cokelat', 'sku' => 'ROT-1', 'is_active' => true],
        ]);

        DB::table('product_variant_units')->insert([
            ['id' => 1, 'merchant_id' => 1, 'product_variant_id' => 1, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'default_price' => 20000, 'cost' => 8000, 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'product_variant_id' => 2, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'default_price' => 15000, 'cost' => 6000, 'is_active' => true],
        ]);

        DB::table('warehouse_stocks')->insert([
            ['merchant_id' => 1, 'warehouse_id' => 1, 'product_variant_id' => 1, 'quantity' => 10, 'avg_cost' => 8000, 'created_at' => now(), 'updated_at' => now()],
            ['merchant_id' => 1, 'warehouse_id' => 1, 'product_variant_id' => 2, 'quantity' => 10, 'avg_cost' => 6000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Shift aktif untuk Merchant A
        DB::table('cashier_shifts')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'branch_id' => 1,
            'user_id' => 1,
            'shift_number' => 'SHF-20261123-000001',
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Transaksi penjualan selesai (5 Kopi Susu @ 20.000 + 2 Roti Bakar @ 15.000 = 130.000)
        DB::table('sales_transactions')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'branch_id' => 1,
            'warehouse_id' => 1,
            'cashier_shift_id' => 1,
            'transaction_number' => 'POS-20261123-000001',
            'status' => 'completed',
            'discount_type' => 'none',
            'discount_value' => 0,
            'subtotal' => 130000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 130000,
            'paid_amount' => 130000,
            'change_amount' => 0,
            'total_cogs' => 52000,
            'sold_at' => now(),
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales_payments')->insert([
            'merchant_id' => 1,
            'sales_transaction_id' => 1,
            'payment_method' => 'cash',
            'amount' => 130000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales_transaction_items')->insert([
            [
                'id' => 1,
                'merchant_id' => 1,
                'sales_transaction_id' => 1,
                'product_id' => 1,
                'product_variant_id' => 1,
                'product_variant_unit_id' => 1,
                'product_name' => 'Kopi Susu',
                'variant_name' => 'Reguler',
                'sku' => 'KOP-1',
                'unit_name' => 'Cup',
                'unit_code' => 'CUP',
                'conversion_value' => 1,
                'quantity' => 5,
                'base_quantity' => 5,
                'unit_price' => 20000,
                'unit_cost' => 8000,
                'total_cogs' => 40000,
                'discount_type' => 'none',
                'discount_value' => 0,
                'discount_amount' => 0,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'subtotal' => 100000,
                'total' => 100000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'merchant_id' => 1,
                'sales_transaction_id' => 1,
                'product_id' => 2,
                'product_variant_id' => 2,
                'product_variant_unit_id' => 2,
                'product_name' => 'Roti Bakar',
                'variant_name' => 'Cokelat',
                'sku' => 'ROT-1',
                'unit_name' => 'Porsi',
                'unit_code' => 'PRS',
                'conversion_value' => 1,
                'quantity' => 2,
                'base_quantity' => 2,
                'unit_price' => 15000,
                'unit_cost' => 6000,
                'total_cogs' => 12000,
                'discount_type' => 'none',
                'discount_value' => 0,
                'discount_amount' => 0,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'subtotal' => 30000,
                'total' => 30000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $numberService = new SalesDocumentNumberService();
        $this->returnService = new SalesReturnService($numberService, new StockService());
        $this->shiftService = new CashierShiftService($numberService);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    public function test_partial_sales_return_restocks_and_reduces_shift_expected_cash_and_can_be_voided(): void
    {
        // Retur 2 Kopi Susu (restock = true -> +2 stok) & 1 Roti Bakar (restock = false / rusak -> stok tidak bertambah)
        $ret = $this->returnService->create([
            'sales_transaction_id' => 1,
            'cashier_shift_id' => 1,
            'refund_method' => 'cash',
            'reason' => 'Pesanan keliru & 1 roti gosong',
            'items' => [
                ['sales_transaction_item_id' => 1, 'quantity' => 2, 'restock' => true],
                ['sales_transaction_item_id' => 2, 'quantity' => 1, 'restock' => false],
            ],
        ]);

        $this->assertSame(SalesReturnStatus::Completed, $ret->status);
        $this->assertStringStartsWith('SRT-', $ret->return_number);
        $this->assertEquals(55000.0, (float) $ret->total_refund); // 40.000 + 15.000
        $this->assertEquals(16000.0, (float) $ret->total_cogs_reversed); // hanya 2 Kopi Susu * 8.000

        // Stok Kopi Susu naik 10 -> 12, Roti Bakar tetap 10 (karena restock = false)
        $stockKopi = (float) DB::table('warehouse_stocks')->where('product_variant_id', 1)->value('quantity');
        $stockRoti = (float) DB::table('warehouse_stocks')->where('product_variant_id', 2)->value('quantity');
        $this->assertEquals(12.0, $stockKopi);
        $this->assertEquals(10.0, $stockRoti);

        // Mutasi stok bertipe sales_return tercatat
        $this->assertTrue(
            DB::table('stock_mutations')
                ->where('mutation_type', MutationType::SalesReturn->value)
                ->where('reference_id', $ret->id)
                ->exists()
        );

        // Expected cash shift berkurang sebesar cash refund 55.000: 100.000 + 130.000 - 55.000 = 175.000
        $shiftInfo = $this->shiftService->show(1);
        $this->assertEquals(55000.0, (float) $shiftInfo['summary']['cash_refunds']);
        $this->assertEquals(175000.0, (float) $shiftInfo['summary']['expected_cash']);

        // Sisa qty yang bisa diretur berkurang (Kopi Susu sisa 3, Roti Bakar sisa 1)
        $returnable = $this->returnService->returnableTransactions();
        $this->assertCount(1, $returnable);
        $this->assertEquals(3.0, $returnable[0]['items'][0]['remaining_quantity']);
        $this->assertEquals(1.0, $returnable[0]['items'][1]['remaining_quantity']);

        // Retur melebihi sisa qty (4 Kopi Susu padahal sisa 3) ditolak
        try {
            $this->returnService->create([
                'sales_transaction_id' => 1,
                'refund_method' => 'cash',
                'reason' => 'Coba retur berlebih',
                'items' => [['sales_transaction_item_id' => 1, 'quantity' => 4, 'restock' => true]],
            ]);
            $this->fail('Seharusnya menolak retur melebihi sisa qty.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.quantity', $e->errors());
        }

        // Void retur -> stok Kopi Susu kembali 12 -> 10, cash_refunds kembali 0
        $voided = $this->returnService->void((int) $ret->id, 'Salah input retur');
        $this->assertSame(SalesReturnStatus::Voided, $voided->status);
        $this->assertEquals(10.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 1)->value('quantity'));
        $this->assertTrue(
            DB::table('stock_mutations')
                ->where('mutation_type', MutationType::SalesReturnVoid->value)
                ->where('reference_id', $ret->id)
                ->exists()
        );

        $shiftInfoAfterVoid = $this->shiftService->show(1);
        $this->assertEquals(0.0, (float) $shiftInfoAfterVoid['summary']['cash_refunds']);
        $this->assertEquals(230000.0, (float) $shiftInfoAfterVoid['summary']['expected_cash']);
    }

    public function test_cross_merchant_isolation_prevents_accessing_other_merchant_returns(): void
    {
        $ret = $this->returnService->create([
            'sales_transaction_id' => 1,
            'refund_method' => 'cash',
            'reason' => 'Retur Merchant A',
            'items' => [['sales_transaction_item_id' => 1, 'quantity' => 1, 'restock' => true]],
        ]);

        MerchantContext::set($this->merchantB);
        $this->assertSame(0, $this->returnService->list()->total());

        $this->expectException(ModelNotFoundException::class);
        $this->returnService->show((int) $ret->id);
    }

    public function test_exchange_return_same_item_and_upgrade_with_settlement_and_void(): void
    {
        // 1. Tukar barang sama (1 Kopi Susu rusak restock=false, ganti 1 Kopi Susu baru @ 20.000 -> impas Rp 0)
        $ex1 = $this->returnService->create([
            'sales_transaction_id' => 1,
            'cashier_shift_id' => 1,
            'return_type' => 'exchange',
            'refund_method' => 'cash',
            'reason' => 'Gelas bocor, ganti baru',
            'items' => [
                ['sales_transaction_item_id' => 1, 'quantity' => 1, 'restock' => false],
            ],
            'exchange_items' => [
                ['product_variant_unit_id' => 1, 'quantity' => 1, 'unit_price' => 20000],
            ],
        ]);

        $this->assertEquals(20000.0, (float) $ex1->total_refund);
        $this->assertEquals(20000.0, (float) $ex1->exchange_total);
        $this->assertEquals(0.0, (float) $ex1->settlement_amount);
        // Stok Kopi Susu turun 10 -> 9 (barang lama tidak direstock, barang pengganti keluar 1)
        $this->assertEquals(9.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 1)->value('quantity'));
        // Expected cash tetap 230.000 (100.000 + 130.000, selisih 0)
        $shift1 = $this->shiftService->show(1);
        $this->assertEquals(230000.0, (float) $shift1['summary']['expected_cash']);

        // 2. Tukar tambah: retur 1 Roti Bakar (15.000, restock=true) tukar 1 Kopi Susu (20.000) -> pelanggan bayar tambahan +5.000
        $ex2 = $this->returnService->create([
            'sales_transaction_id' => 1,
            'cashier_shift_id' => 1,
            'return_type' => 'exchange',
            'refund_method' => 'cash',
            'reason' => 'Tukar Roti Bakar ke Kopi Susu',
            'items' => [
                ['sales_transaction_item_id' => 2, 'quantity' => 1, 'restock' => true],
            ],
            'exchange_items' => [
                ['product_variant_unit_id' => 1, 'quantity' => 1, 'unit_price' => 20000],
            ],
        ]);

        $this->assertEquals(5000.0, (float) $ex2->settlement_amount);
        // Stok Roti Bakar naik 10 -> 11, Kopi Susu turun 9 -> 8
        $this->assertEquals(11.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 2)->value('quantity'));
        $this->assertEquals(8.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 1)->value('quantity'));
        // Expected cash bertambah +5.000 -> 235.000
        $shift2 = $this->shiftService->show(1);
        $this->assertEquals(5000.0, (float) $shift2['summary']['cash_exchange_in']);
        $this->assertEquals(235000.0, (float) $shift2['summary']['expected_cash']);

        // 3. Void tukar tambah -> stok Roti Bakar kembali 10, Kopi Susu kembali 9, expected_cash kembali 230.000
        $this->returnService->void((int) $ex2->id, 'Batal tukar tambah');
        $this->assertEquals(10.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 2)->value('quantity'));
        $this->assertEquals(9.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 1)->value('quantity'));
        $shift3 = $this->shiftService->show(1);
        $this->assertEquals(230000.0, (float) $shift3['summary']['expected_cash']);

        // 4. Biaya retur/pengiriman ditanggung Pelanggan vs ditanggung Toko
        // Refund 1 Kopi Susu (20.000) + biaya retur 3.000 ditanggung pelanggan -> kasir keluar 17.000 (settlement -17.000)
        $feeCustomer = $this->returnService->create([
            'sales_transaction_id' => 1,
            'cashier_shift_id' => 1,
            'return_type' => 'refund',
            'refund_method' => 'cash',
            'return_fee' => 3000,
            'return_fee_bearer' => 'customer',
            'reason' => 'Ongkir retur ditanggung pelanggan',
            'items' => [['sales_transaction_item_id' => 1, 'quantity' => 1, 'restock' => true]],
        ]);
        $this->assertEquals(-17000.0, (float) $feeCustomer->settlement_amount);

        // Refund 1 Roti Bakar (15.000) + biaya retur 5.000 ditanggung toko -> kasir keluar 20.000 (settlement -20.000)
        $feeMerchant = $this->returnService->create([
            'sales_transaction_id' => 1,
            'cashier_shift_id' => 1,
            'return_type' => 'refund',
            'refund_method' => 'cash',
            'return_fee' => 5000,
            'return_fee_bearer' => 'merchant',
            'reason' => 'Ongkir retur ditanggung toko',
            'items' => [['sales_transaction_item_id' => 2, 'quantity' => 1, 'restock' => true]],
        ]);
        $this->assertEquals(-20000.0, (float) $feeMerchant->settlement_amount);

        // Expected cash: 230.000 - 17.000 - 20.000 = 193.000
        $shift4 = $this->shiftService->show(1);
        $this->assertEquals(193000.0, (float) $shift4['summary']['expected_cash']);
    }

    private function createSchema(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->unsignedBigInteger('primary_warehouse_id')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->boolean('track_stock')->default(true);
            $table->boolean('can_be_sold')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variant_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('unit_id')->default(1);
            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->boolean('is_base')->default(true);
            $table->decimal('default_price', 15, 2)->default(0);
            $table->decimal('cost', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('avg_cost', 15, 4)->default(0);
            $table->timestamp('last_mutated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('mutation_type');
            $table->decimal('quantity', 15, 4);
            $table->decimal('quantity_before', 15, 4)->default(0);
            $table->decimal('quantity_after', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('avg_cost_after', 15, 4)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('mutated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('doc_type', 20);
            $table->string('period', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['merchant_id', 'doc_type', 'period']);
        });

        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->string('shift_number');
            $table->string('status')->default('open');
            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('actual_cash', 15, 2)->nullable();
            $table->decimal('variance', 15, 2)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cashier_shift_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('cashier_shift_id');
            $table->string('type');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('cashier_shift_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('transaction_number');
            $table->string('status')->default('completed');
            $table->string('discount_type')->default('none');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sales_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('sales_transaction_id');
            $table->string('payment_method');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reference_number')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('sales_transaction_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('product_variant_unit_id');
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('unit_name');
            $table->string('unit_code')->nullable();
            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->decimal('quantity', 15, 4);
            $table->decimal('base_quantity', 15, 4);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);
            $table->string('discount_type')->default('none');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('sales_transaction_id');
            $table->unsignedBigInteger('cashier_shift_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('return_number', 50);
            $table->string('status', 20)->default('completed');
            $table->string('return_type', 20)->default('refund');
            $table->string('refund_method', 30)->default('cash');
            $table->text('reason');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_refund', 15, 2)->default(0);
            $table->decimal('exchange_total', 15, 2)->default(0);
            $table->decimal('return_fee', 15, 2)->default(0);
            $table->string('return_fee_bearer', 20)->default('customer');
            $table->decimal('settlement_amount', 15, 2)->default(0);
            $table->decimal('total_cogs_reversed', 15, 2)->default(0);
            $table->decimal('total_cogs_exchange', 15, 2)->default(0);
            $table->timestamp('returned_at');
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('sales_return_id');
            $table->unsignedBigInteger('sales_transaction_item_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('product_variant_unit_id');
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('unit_name', 100);
            $table->string('unit_code', 30)->nullable();
            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->decimal('quantity', 15, 4);
            $table->decimal('base_quantity', 15, 4);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total_refund', 15, 2)->default(0);
            $table->boolean('restock')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_return_exchange_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('sales_return_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('product_variant_unit_id');
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('unit_name', 100);
            $table->string('unit_code', 30)->nullable();
            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->decimal('quantity', 15, 4);
            $table->decimal('base_quantity', 15, 4);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
}
