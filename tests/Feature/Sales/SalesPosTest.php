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
use Modules\Merchant\Models\Merchant;
use Modules\Sales\Enums\CashierShiftStatus;
use Modules\Sales\Enums\SalesTransactionStatus;
use Modules\Sales\Services\CashierShiftService;
use Modules\Sales\Services\PosCatalogService;
use Modules\Sales\Services\SalesTransactionService;
use Tests\TestCase;

class SalesPosTest extends TestCase
{
    private Merchant $merchantA;
    private Merchant $merchantB;
    private CashierShiftService $shiftService;
    private PosCatalogService $catalogService;
    private SalesTransactionService $txService;

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
                'name' => 'Merchant Kopi A',
                'metadata' => json_encode(['inventory' => ['allow_negative_stock' => false]]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'Merchant Retail B',
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
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Gudang Utama A', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 2, 'name' => 'Gudang Utama B', 'is_active' => true],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Cabang Pusat A', 'primary_warehouse_id' => 1, 'is_main' => true, 'is_active' => true],
            ['id' => 2, 'merchant_id' => 2, 'name' => 'Cabang Pusat B', 'primary_warehouse_id' => 2, 'is_main' => true, 'is_active' => true],
            ['id' => 3, 'merchant_id' => 1, 'name' => 'Cabang Kedua A', 'primary_warehouse_id' => 1, 'is_main' => false, 'is_active' => true],
        ]);

        DB::table('units')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Cup', 'code' => 'CUP', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Box', 'code' => 'BOX', 'is_active' => true],
        ]);

        DB::table('products')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Es Kopi Susu', 'type' => 'product', 'track_stock' => true, 'can_be_sold' => true, 'is_taxable' => true, 'tax_rate' => 10, 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Roti Bakar', 'type' => 'product', 'track_stock' => true, 'can_be_sold' => true, 'is_taxable' => false, 'tax_rate' => 0, 'is_active' => true],
            ['id' => 3, 'merchant_id' => 1, 'name' => 'Bahan Baku Internal', 'type' => 'product', 'track_stock' => true, 'can_be_sold' => false, 'is_taxable' => false, 'tax_rate' => 0, 'is_active' => true],
        ]);

        DB::table('product_variants')->insert([
            ['id' => 10, 'merchant_id' => 1, 'product_id' => 1, 'name' => 'Regular', 'sku' => 'KOP-REG', 'is_active' => true],
            ['id' => 20, 'merchant_id' => 1, 'product_id' => 2, 'name' => 'Coklat', 'sku' => 'ROT-COK', 'is_active' => true],
            ['id' => 30, 'merchant_id' => 1, 'product_id' => 3, 'name' => 'Biji Mentah', 'sku' => 'RAW-001', 'is_active' => true],
        ]);

        DB::table('product_variant_units')->insert([
            ['id' => 100, 'merchant_id' => 1, 'product_variant_id' => 10, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'barcode' => '8990001', 'cost' => 8000, 'default_price' => 20000, 'is_active' => true],
            ['id' => 101, 'merchant_id' => 1, 'product_variant_id' => 10, 'unit_id' => 2, 'conversion_value' => 6, 'is_base' => false, 'barcode' => '8990002', 'cost' => 48000, 'default_price' => 110000, 'is_active' => true],
            ['id' => 200, 'merchant_id' => 1, 'product_variant_id' => 20, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'barcode' => '8990003', 'cost' => 6000, 'default_price' => 15000, 'is_active' => true],
            ['id' => 300, 'merchant_id' => 1, 'product_variant_id' => 30, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'barcode' => '8990004', 'cost' => 50000, 'default_price' => 0, 'is_active' => true],
        ]);

        DB::table('warehouse_stocks')->insert([
            ['merchant_id' => 1, 'warehouse_id' => 1, 'product_variant_id' => 10, 'quantity' => 20, 'avg_cost' => 8000, 'created_at' => now(), 'updated_at' => now()],
            ['merchant_id' => 1, 'warehouse_id' => 1, 'product_variant_id' => 20, 'quantity' => 10, 'avg_cost' => 6000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('branch_active_products')->insert([
            ['merchant_id' => 1, 'branch_id' => 1, 'product_variant_id' => 10, 'source' => 'assortment', 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['merchant_id' => 1, 'branch_id' => 1, 'product_variant_id' => 20, 'source' => 'assortment', 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['merchant_id' => 1, 'branch_id' => 3, 'product_variant_id' => 10, 'source' => 'assortment', 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->shiftService = app(CashierShiftService::class);
        $this->catalogService = app(PosCatalogService::class);
        $this->txService = app(SalesTransactionService::class);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    public function test_pos_catalog_only_returns_sellable_products_with_stock(): void
    {
        $result = $this->catalogService->catalog(['branch_id' => 1]);

        $this->assertCount(2, $result['data']);
        $skus = collect($result['data'])->pluck('sku')->all();
        $this->assertContains('KOP-REG', $skus);
        $this->assertContains('ROT-COK', $skus);
        $this->assertNotContains('RAW-001', $skus);

        $kopi = collect($result['data'])->firstWhere('sku', 'KOP-REG');
        $this->assertEquals(20.0, $kopi['on_hand_stock']);
        $this->assertCount(2, $kopi['units']);
    }

    public function test_pos_catalog_filters_by_branch_assigned_products_and_case_insensitive_search(): void
    {
        // Branch 3 only has variant 10 (KOP-REG) assigned, NOT variant 20 (ROT-COK)
        $branch3Result = $this->catalogService->catalog(['branch_id' => 3]);
        $this->assertCount(1, $branch3Result['data']);
        $this->assertEquals('KOP-REG', $branch3Result['data'][0]['sku']);

        // Case-insensitive search by product name, variant name, SKU, and barcode
        $byName = $this->catalogService->catalog(['branch_id' => 1, 'search' => 'kopi susu']);
        $this->assertCount(1, $byName['data']);
        $this->assertEquals('KOP-REG', $byName['data'][0]['sku']);

        $bySku = $this->catalogService->catalog(['branch_id' => 1, 'search' => 'rot-cok']);
        $this->assertCount(1, $bySku['data']);
        $this->assertEquals('ROT-COK', $bySku['data'][0]['sku']);

        $byBarcode = $this->catalogService->catalog(['branch_id' => 1, 'search' => '8990003']);
        $this->assertCount(1, $byBarcode['data']);
        $this->assertEquals('ROT-COK', $byBarcode['data'][0]['sku']);

        // Search on Branch 3 for product not assigned to Branch 3 returns empty
        $notAssigned = $this->catalogService->catalog(['branch_id' => 3, 'search' => 'roti']);
        $this->assertCount(0, $notAssigned['data']);
    }

    public function test_pos_customer_search_is_case_insensitive_and_merchant_isolated(): void
    {
        DB::table('customers')->insert([
            ['id' => 1, 'merchant_id' => 1, 'code' => 'CUST-001', 'name' => 'Budi Santoso', 'phone' => '08123456789'],
            ['id' => 2, 'merchant_id' => 1, 'code' => 'CUST-002', 'name' => 'Siti Aminah', 'phone' => '08987654321'],
            ['id' => 3, 'merchant_id' => 2, 'code' => 'CUST-999', 'name' => 'Budi Merchant B', 'phone' => '08111111111'],
        ]);

        // Case-insensitive by name, isolated to Merchant A
        $byName = $this->catalogService->searchCustomers(['search' => 'budi']);
        $this->assertCount(1, $byName);
        $this->assertEquals('Budi Santoso', $byName[0]['name']);

        // By phone
        $byPhone = $this->catalogService->searchCustomers(['search' => '98765']);
        $this->assertCount(1, $byPhone);
        $this->assertEquals('Siti Aminah', $byPhone[0]['name']);

        // By code (case-insensitive)
        $byCode = $this->catalogService->searchCustomers(['search' => 'cust-001']);
        $this->assertCount(1, $byCode);
        $this->assertEquals(1, $byCode[0]['id']);

        // Merchant B only sees its own customer
        MerchantContext::set($this->merchantB);
        $merchantBRes = $this->catalogService->searchCustomers(['search' => 'budi']);
        $this->assertCount(1, $merchantBRes);
        $this->assertEquals('Budi Merchant B', $merchantBRes[0]['name']);
    }

    public function test_checkout_requires_open_shift(): void
    {
        $this->expectException(ValidationException::class);

        $this->txService->create([
            'branch_id' => 1,
            'items' => [
                ['product_variant_unit_id' => 200, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 15000],
            ],
        ]);
    }

    public function test_full_shift_and_split_payment_checkout_with_idempotency_and_void(): void
    {
        // 1. Open shift with Rp200.000
        $shift = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 200000,
        ]);
        $this->assertEquals(CashierShiftStatus::Open, $shift->status);

        // 2. Add cash_in (+50.000) & cash_out (-20.000)
        $this->shiftService->addMovement($shift->id, ['type' => 'cash_in', 'amount' => 50000, 'reason' => 'Tambah receh']);
        $this->shiftService->addMovement($shift->id, ['type' => 'cash_out', 'amount' => 20000, 'reason' => 'Beli es batu']);

        // 3. Checkout with split payment (Cash + QRIS) & Idempotency-Key
        // Item 1: 2x Es Kopi Susu (20.000) = 40.000 + 10% tax (4.000) = 44.000 | COGS = 2 * 8.000 = 16.000
        // Item 2: 2x Roti Bakar (15.000) = 30.000 (0% tax) = 30.000 | COGS = 2 * 6.000 = 12.000
        // Total = 74.000; Pay QRIS 30.000 + Cash 50.000 = 80.000 -> Change = 6.000; Net Cash to drawer = 44.000
        $payload = [
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'items' => [
                ['product_variant_unit_id' => 200, 'quantity' => 2],
                ['product_variant_unit_id' => 100, 'quantity' => 2],
            ],
            'payments' => [
                ['payment_method' => 'qris', 'amount' => 30000, 'reference_number' => 'QR-123'],
                ['payment_method' => 'cash', 'amount' => 50000],
            ],
        ];

        $tx = $this->txService->create($payload, 'idem-key-001');
        $this->assertEquals(SalesTransactionStatus::Completed, $tx->status);
        $this->assertEquals(70000.0, (float) $tx->subtotal);
        $this->assertEquals(4000.0, (float) $tx->tax_amount);
        $this->assertEquals(74000.0, (float) $tx->grand_total);
        $this->assertEquals(80000.0, (float) $tx->paid_amount);
        $this->assertEquals(6000.0, (float) $tx->change_amount);
        $this->assertEquals(28000.0, (float) $tx->total_cogs);

        // Verify stock deducted
        $stockKopi = (float) DB::table('warehouse_stocks')->where('product_variant_id', 10)->value('quantity');
        $stockRoti = (float) DB::table('warehouse_stocks')->where('product_variant_id', 20)->value('quantity');
        $this->assertEquals(18.0, $stockKopi);
        $this->assertEquals(8.0, $stockRoti);

        // 4. Replay with same Idempotency-Key -> returns same tx, NO double deduction
        $txReplay = $this->txService->create($payload, 'idem-key-001');
        $this->assertEquals($tx->id, $txReplay->id);
        $this->assertEquals(18.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 10)->value('quantity'));

        // 5. Close shift and verify expected cash:
        // opening(200.000) + net_cash_sales(44.000) + cash_in(50.000) - cash_out(20.000) = 274.000
        $closed = $this->shiftService->close($shift->id, ['actual_cash' => 270000, 'notes' => 'Kurang 4rb']);
        $this->assertEquals(CashierShiftStatus::Closed, $closed['shift']->status);
        $this->assertEquals(274000.0, (float) $closed['shift']->expected_cash);
        $this->assertEquals(270000.0, (float) $closed['shift']->actual_cash);
        $this->assertEquals(-4000.0, (float) $closed['shift']->variance);

        // 6. Void transaction -> restores stock & records sales_void mutation
        $voided = $this->txService->void($tx->id, 'Salah input pesanan');
        $this->assertEquals(SalesTransactionStatus::Voided, $voided->status);
        $this->assertEquals(20.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 10)->value('quantity'));
        $this->assertEquals(10.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 20)->value('quantity'));
        $this->assertEquals(
            2,
            DB::table('stock_mutations')->where('mutation_type', MutationType::SalesVoid->value)->count()
        );
    }

    public function test_hold_bill_does_not_deduct_stock_until_completed(): void
    {
        $shift = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 100000,
        ]);

        // Hold bill (1 Box = 6 Cup Es Kopi Susu)
        $held = $this->txService->create([
            'branch_id' => 1,
            'status' => 'held',
            'hold_label' => 'Meja 04',
            'items' => [
                ['product_variant_unit_id' => 101, 'quantity' => 1],
            ],
        ]);

        $this->assertEquals(SalesTransactionStatus::Held, $held->status);
        // Stock unchanged
        $this->assertEquals(20.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 10)->value('quantity'));

        // Complete held bill (110.000 + 10% tax = 121.000)
        $completed = $this->txService->completeHeld($held->id, [
            'cashier_shift_id' => $shift->id,
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 150000],
            ],
        ], 'idem-held-001');

        $this->assertEquals(SalesTransactionStatus::Completed, $completed->status);
        $this->assertEquals(121000.0, (float) $completed->grand_total);
        $this->assertEquals(29000.0, (float) $completed->change_amount);
        // Stock deducted by 6 base units (20 - 6 = 14)
        $this->assertEquals(14.0, (float) DB::table('warehouse_stocks')->where('product_variant_id', 10)->value('quantity'));
    }

    public function test_rejects_checkout_when_stock_insufficient(): void
    {
        $shift = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 50000,
        ]);

        $this->expectException(ValidationException::class);

        $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'items' => [
                ['product_variant_unit_id' => 200, 'quantity' => 99], // only 10 in stock
            ],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 2000000],
            ],
        ]);
    }

    public function test_cross_merchant_isolation_blocks_access_to_other_merchant_shift_and_transaction(): void
    {
        $shiftA = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 100000,
        ]);

        $txA = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shiftA->id,
            'items' => [
                ['product_variant_unit_id' => 200, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 15000],
            ],
        ]);

        // Switch context to Merchant B
        MerchantContext::set($this->merchantB);

        try {
            $this->txService->show($txA->id);
            $this->fail('Merchant B should not be able to read Merchant A transaction.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        try {
            $this->shiftService->show($shiftA->id);
            $this->fail('Merchant B should not be able to read Merchant A shift.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_pos_catalog_batch_resolves_prices_without_n_plus_one_queries(): void
    {
        // Configure branch-specific fixed price for unit 100 and margin price for unit 200
        DB::table('product_prices')->insert([
            [
                'merchant_id' => 1,
                'product_variant_unit_id' => 100,
                'price_list_id' => null,
                'branch_id' => 1,
                'min_qty' => 1,
                'pricing_mode' => 'fixed',
                'price' => 22500,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'merchant_id' => 1,
                'product_variant_unit_id' => 200,
                'price_list_id' => null,
                'branch_id' => 1,
                'min_qty' => 1,
                'pricing_mode' => 'margin',
                'price' => 50, // cost 6000 * 1.5 = 9000
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $this->catalogService->catalog(['branch_id' => 1]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $kopi = collect($result['data'])->firstWhere('sku', 'KOP-REG');
        $roti = collect($result['data'])->firstWhere('sku', 'ROT-COK');

        $kopiCup = collect($kopi['units'])->firstWhere('product_variant_unit_id', 100);
        $kopiBox = collect($kopi['units'])->firstWhere('product_variant_unit_id', 101);
        $rotiCup = collect($roti['units'])->firstWhere('product_variant_unit_id', 200);

        $this->assertEquals(22500.0, $kopiCup['resolved_price']);
        $this->assertEquals('product_price', $kopiCup['pricing_source']);
        $this->assertEquals(110000.0, $kopiBox['resolved_price']);
        $this->assertEquals('default_price', $kopiBox['pricing_source']);
        $this->assertEquals(9000.0, $rotiCup['resolved_price']);
        $this->assertEquals('product_price', $rotiCup['pricing_source']);

        // Ensure product_prices is queried at most once (batch whereIn), not per unit
        $priceQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'product_prices'));
        $this->assertCount(1, $priceQueries);
    }

    public function test_checkout_ignores_client_supplied_unit_price_and_uses_server_price(): void
    {
        $shift = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 100000,
        ]);

        // Client attempts to tamper unit_price to Rp 1 (real server price is Rp 15.000)
        $tx = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'items' => [
                ['product_variant_unit_id' => 200, 'quantity' => 2, 'unit_price' => 1],
            ],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 30000],
            ],
        ]);

        $this->assertEquals(30000.0, (float) $tx->subtotal);
        $this->assertEquals(30000.0, (float) $tx->grand_total);
        $this->assertEquals(15000.0, (float) $tx->items->first()->unit_price);
    }

    public function test_pos_stocks_endpoint_returns_lightweight_variant_stock_map(): void
    {
        $stocks = $this->catalogService->stocks(1, [10, 20, 999]);

        $this->assertSame([
            10 => 20.0,
            20 => 10.0,
            999 => 0.0,
        ], $stocks);
    }

    public function test_document_sequences_increment_independently_for_shift_and_transaction(): void
    {
        $shift = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 50000,
        ]);

        $tx1 = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'items' => [['product_variant_unit_id' => 200, 'quantity' => 1]],
            'payments' => [['payment_method' => 'cash', 'amount' => 15000]],
        ]);

        $tx2 = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'items' => [['product_variant_unit_id' => 200, 'quantity' => 1]],
            'payments' => [['payment_method' => 'cash', 'amount' => 15000]],
        ]);

        $this->assertStringEndsWith('-000001', $shift->shift_number);
        $this->assertStringEndsWith('-000001', $tx1->transaction_number);
        $this->assertStringEndsWith('-000002', $tx2->transaction_number);
    }

    public function test_checkout_with_100_percent_discount_zero_grand_total_succeeds_without_payments(): void
    {
        $shift = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 50000,
        ]);

        $tx = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'discount_type' => 'percentage',
            'discount_value' => 100,
            'items' => [
                ['product_variant_unit_id' => 200, 'quantity' => 1],
            ],
            'payments' => [],
        ]);

        $this->assertEquals(SalesTransactionStatus::Completed, $tx->status);
        $this->assertEquals(0.0, (float) $tx->grand_total);
        $this->assertEquals(0.0, (float) $tx->paid_amount);
        $this->assertCount(0, $tx->payments);
    }

    public function test_checkout_batch_resolves_tiered_prices_in_single_query(): void
    {
        DB::table('product_prices')->insert([
            [
                'merchant_id' => 1,
                'product_variant_unit_id' => 100,
                'price_list_id' => null,
                'branch_id' => 1,
                'min_qty' => 2,
                'pricing_mode' => 'fixed',
                'price' => 18000,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'merchant_id' => 1,
                'product_variant_unit_id' => 200,
                'price_list_id' => null,
                'branch_id' => 1,
                'min_qty' => 1,
                'pricing_mode' => 'fixed',
                'price' => 14000,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $shift = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 50000,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $tx = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'items' => [
                ['product_variant_unit_id' => 100, 'quantity' => 2], // 2 * 18000 = 36000 + 10% tax = 39600
                ['product_variant_unit_id' => 200, 'quantity' => 1], // 1 * 14000 = 14000
            ],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 55000],
            ],
        ]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertEquals(50000.0, (float) $tx->subtotal);
        $this->assertEquals(53600.0, (float) $tx->grand_total);
        $priceQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'product_prices'));
        $this->assertCount(1, $priceQueries);
    }

    public function test_void_soft_deleted_non_stock_product_does_not_create_ghost_stock(): void
    {
        DB::table('products')->insert([
            'id' => 4,
            'merchant_id' => 1,
            'name' => 'Jasa Bungkus Kado',
            'type' => 'service',
            'track_stock' => false,
            'can_be_sold' => true,
            'is_taxable' => false,
            'tax_rate' => 0,
            'is_active' => true,
        ]);
        DB::table('product_variants')->insert([
            'id' => 40,
            'merchant_id' => 1,
            'product_id' => 4,
            'name' => 'Standar',
            'sku' => 'SVC-001',
            'is_active' => true,
        ]);
        DB::table('product_variant_units')->insert([
            'id' => 400,
            'merchant_id' => 1,
            'product_variant_id' => 40,
            'unit_id' => 1,
            'conversion_value' => 1,
            'is_base' => true,
            'cost' => 0,
            'default_price' => 5000,
            'is_active' => true,
        ]);
        DB::table('branch_active_products')->insert([
            'merchant_id' => 1,
            'branch_id' => 1,
            'product_variant_id' => 40,
            'source' => 'assortment',
            'resolved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shift = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 50000,
        ]);

        $tx = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'items' => [['product_variant_unit_id' => 400, 'quantity' => 1]],
            'payments' => [['payment_method' => 'cash', 'amount' => 5000]],
        ]);

        // Soft-delete service product before voiding
        DB::table('products')->where('id', 4)->update(['deleted_at' => now()]);

        $this->txService->void($tx->id, 'Batal jasa');

        $this->assertDatabaseMissing('warehouse_stocks', [
            'merchant_id' => 1,
            'warehouse_id' => 1,
            'product_variant_id' => 40,
        ]);
    }

    public function test_checkout_rejects_product_not_assigned_to_branch(): void
    {
        // Branch 3 only has variant 10 assigned, NOT variant 20 (unit 200)
        $shift = $this->shiftService->open([
            'branch_id' => 3,
            'user_id' => 1,
            'opening_cash' => 50000,
        ]);

        $this->expectException(ValidationException::class);

        $this->txService->create([
            'branch_id' => 3,
            'cashier_shift_id' => $shift->id,
            'items' => [['product_variant_unit_id' => 200, 'quantity' => 1]],
            'payments' => [['payment_method' => 'cash', 'amount' => 15000]],
        ]);
    }

    public function test_cashier_cannot_operate_another_cashiers_shift_without_manage_permission(): void
    {
        $shiftOwnedByUser1 = $this->shiftService->open([
            'branch_id' => 1,
            'user_id' => 1,
            'opening_cash' => 100000,
        ]);

        $otherCashier = new class extends \Illuminate\Foundation\Auth\User
        {
            public $id = 99;

            public function getAuthIdentifier()
            {
                return 99;
            }

            public function hasRole(string $role): bool
            {
                return false;
            }

            public function can($abilities, $arguments = []): bool
            {
                return false;
            }
        };

        \Illuminate\Support\Facades\Auth::guard('merchant')->setUser($otherCashier);

        try {
            $this->shiftService->close($shiftOwnedByUser1->id, ['actual_cash' => 100000]);
            $this->fail('Cashier without sales.shift.manage should not be able to close another cashier shift.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        } finally {
            \Illuminate\Support\Facades\Auth::guard('merchant')->logout();
        }
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
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->string('email');
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

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->string('type')->default('product');
            $table->boolean('track_stock')->default(true);
            $table->boolean('can_be_sold')->default(true);
            $table->boolean('is_taxable')->default(false);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('path');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
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

        Schema::create('product_variant_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->unsignedBigInteger('product_variant_id');
            $table->string('path');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_variant_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('unit_id');
            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->boolean('is_base')->default(false);
            $table->string('barcode')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('default_price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_variant_unit_id');
            $table->unsignedBigInteger('price_list_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('min_qty', 15, 4)->default(1);
            $table->string('pricing_mode')->default('fixed');
            $table->decimal('price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branch_active_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('source')->default('assortment');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'product_variant_id']);
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
            $table->unique(['merchant_id', 'warehouse_id', 'product_variant_id']);
        });

        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('mutation_type', 50);
            $table->decimal('quantity', 15, 4);
            $table->decimal('quantity_before', 15, 4);
            $table->decimal('quantity_after', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 4)->default(0);
            $table->decimal('avg_cost_after', 15, 4)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('mutated_at')->nullable();
            $table->timestamps();
        });

        // Run the Sales module migrations directly
        foreach (glob(base_path('Modules/Sales/database/migrations/*.php')) as $migrationFile) {
            $migration = require $migrationFile;
            $migration->up();
        }
    }
}
