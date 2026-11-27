<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Sales\Services\CashierShiftService;
use Modules\Sales\Services\PosConfigResolver;
use Modules\Sales\Services\SalesTransactionService;
use Modules\Sales\Services\TableManagementService;
use Modules\User\Models\User;
use Tests\TestCase;

class TableManagementTest extends TestCase
{
    private Merchant $cafeMerchant;
    private TableManagementService $tableService;
    private CashierShiftService $shiftService;
    private SalesTransactionService $txService;

    protected function setUp(): void
    {
        parent::setUp();
        MerchantContext::clear();
        Cache::flush();

        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('These tests require the isolated in-memory SQLite database from phpunit.xml.');
        }

        $this->createSchema();

        DB::table('merchants')->insert([
            'id' => 1,
            'name' => 'Resto Nusantara',
            'industry_code' => 'cafe',
            'metadata' => json_encode(['inventory' => ['allow_negative_stock' => false]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cafeMerchant = Merchant::query()->findOrFail(1);
        MerchantContext::set($this->cafeMerchant);

        DB::table('users')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'name' => 'Kasir Resto',
            'email' => 'resto@test.id',
            'password' => 'secret',
        ]);
        $this->actingAs(User::query()->findOrFail(1), 'merchant');

        DB::table('warehouses')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'name' => 'Gudang Resto',
            'is_active' => true,
        ]);

        DB::table('branches')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'name' => 'Cabang Utama',
            'primary_warehouse_id' => 1,
            'is_main' => true,
            'is_active' => true,
        ]);

        DB::table('units')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'name' => 'Porsi',
            'code' => 'PRS',
            'is_active' => true,
        ]);

        DB::table('products')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Nasi Goreng', 'type' => 'product', 'track_stock' => false, 'can_be_sold' => true, 'is_taxable' => true, 'tax_rate' => 10, 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Es Teh Manis', 'type' => 'product', 'track_stock' => false, 'can_be_sold' => true, 'is_taxable' => true, 'tax_rate' => 10, 'is_active' => true],
        ]);

        DB::table('product_variants')->insert([
            ['id' => 10, 'merchant_id' => 1, 'product_id' => 1, 'name' => 'Spesial', 'sku' => 'NSG-01', 'is_active' => true],
            ['id' => 20, 'merchant_id' => 1, 'product_id' => 2, 'name' => 'Regular', 'sku' => 'EST-01', 'is_active' => true],
        ]);

        DB::table('product_variant_units')->insert([
            ['id' => 100, 'merchant_id' => 1, 'product_variant_id' => 10, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'cost' => 12000, 'default_price' => 30000, 'is_active' => true],
            ['id' => 200, 'merchant_id' => 1, 'product_variant_id' => 20, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'cost' => 2000, 'default_price' => 10000, 'is_active' => true],
        ]);

        DB::table('branch_active_products')->insert([
            ['merchant_id' => 1, 'branch_id' => 1, 'product_variant_id' => 10, 'source' => 'assortment', 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['merchant_id' => 1, 'branch_id' => 1, 'product_variant_id' => 20, 'source' => 'assortment', 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->tableService = app(TableManagementService::class);
        $this->shiftService = app(CashierShiftService::class);
        $this->txService = app(SalesTransactionService::class);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        Cache::flush();
        parent::tearDown();
    }

    public function test_full_open_bill_move_merge_split_and_auto_release_lifecycle(): void
    {
        $indoor = $this->tableService->createArea(['branch_id' => 1, 'name' => 'Indoor AC']);
        $t1 = $this->tableService->createTable(['branch_id' => 1, 'dining_area_id' => $indoor->id, 'code' => 'T-01', 'name' => 'Meja 1', 'capacity' => 4]);
        $t2 = $this->tableService->createTable(['branch_id' => 1, 'dining_area_id' => $indoor->id, 'code' => 'T-02', 'name' => 'Meja 2', 'capacity' => 4]);
        $t3 = $this->tableService->createTable(['branch_id' => 1, 'dining_area_id' => $indoor->id, 'code' => 'T-03', 'name' => 'Meja 3', 'capacity' => 6]);

        // 1. Open Bill on T-01 (1 Nasi Goreng = 30.000 + 10% tax = 33.000)
        $order1 = $this->tableService->saveTableOrder($t1['id'], [
            'guest_count' => 2,
            'items' => [
                ['product_variant_unit_id' => 100, 'quantity' => 1],
            ],
        ]);
        $this->assertSame('occupied', $order1['table']['status']);
        $this->assertSame(33000.0, (float) $order1['transaction']->grand_total);

        // 2. Add item to existing Open Bill on T-01 (+ 1 Es Teh = 40.000 + 10% tax = 44.000)
        $order1Updated = $this->tableService->saveTableOrder($t1['id'], [
            'guest_count' => 2,
            'items' => [
                ['product_variant_unit_id' => 100, 'quantity' => 1],
                ['product_variant_unit_id' => 200, 'quantity' => 1],
            ],
        ]);
        $this->assertSame($order1['transaction']->id, $order1Updated['transaction']->id);
        $this->assertSame(44000.0, (float) $order1Updated['transaction']->grand_total);

        // 3. Move T-01 -> T-02
        $moved = $this->tableService->moveTable($t1['id'], $t2['id']);
        $this->assertSame('available', $moved['from_table']['status']);
        $this->assertNull($moved['from_table']['active_transaction_id']);
        $this->assertSame('occupied', $moved['to_table']['status']);
        $this->assertSame($order1['transaction']->id, $moved['to_table']['active_transaction_id']);

        // 4. Open Bill on T-03 (1 Es Teh = 11.000), then Merge T-03 into T-02 -> T-02 total = 55.000, T-03 available
        $this->tableService->saveTableOrder($t3['id'], [
            'guest_count' => 1,
            'items' => [
                ['product_variant_unit_id' => 200, 'quantity' => 1],
            ],
        ]);
        $merged = $this->tableService->mergeTables($t3['id'], $t2['id']);
        $this->assertSame('available', $merged['source_table']['status']);
        $this->assertSame(3, $merged['target_table']['guest_count']);
        $this->assertSame(55000.0, (float) $merged['transaction']->grand_total);
        $this->assertCount(3, $merged['transaction']->items);

        // 5. Split Bill on T-02: pay 1 Nasi Goreng (33.000), leaving 2 Es Teh (22.000) still open on T-02
        $shift = $this->shiftService->open(['branch_id' => 1, 'opening_cash' => 100000]);
        $nasiGorengItemId = $merged['transaction']->items->firstWhere('product_id', 1)->id;

        $split = $this->tableService->splitBill($t2['id'], [
            'cashier_shift_id' => $shift->id,
            'item_ids' => [$nasiGorengItemId],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 35000],
            ],
        ]);

        $this->assertSame('completed', $split['paid_transaction']->status->value);
        $this->assertSame(33000.0, (float) $split['paid_transaction']->grand_total);
        $this->assertNotNull($split['remaining_transaction']);
        $this->assertSame(22000.0, (float) $split['remaining_transaction']->grand_total);
        $this->assertSame('occupied', $split['table']['status']);

        // 6. Complete remaining Open Bill on T-02 -> auto-releases T-02 back to 'available'
        $finalTx = $this->txService->completeHeld($split['remaining_transaction']->id, [
            'cashier_shift_id' => $shift->id,
            'payments' => [
                ['payment_method' => 'qris', 'amount' => 22000],
            ],
        ]);

        $this->assertSame('completed', $finalTx->status->value);
        $list = $this->tableService->listByBranch(1);
        $t2Final = collect($list['tables'])->firstWhere('id', $t2['id']);
        $this->assertSame('available', $t2Final['status']);
        $this->assertNull($t2Final['active_transaction_id']);
    }

    public function test_pay_first_dine_in_with_table_marks_occupied_paid_and_dispatches_kds_ticket(): void
    {
        $indoor = $this->tableService->createArea(['branch_id' => 1, 'name' => 'Coffee Bar']);
        $t5 = $this->tableService->createTable([
            'branch_id' => 1,
            'dining_area_id' => $indoor->id,
            'code' => 'T-05',
            'name' => 'Meja 5',
            'capacity' => 4,
        ]);

        // Create default kitchen station for branch 1
        DB::table('kitchen_stations')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'branch_id' => 1,
            'code' => 'MAIN',
            'name' => 'Main Kitchen',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shift = $this->shiftService->open(['branch_id' => 1, 'opening_cash' => 100000]);

        // Direct Pay-First Dine-in checkout with dining_table_id
        $tx = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'status' => 'completed',
            'order_type' => 'dine_in',
            'dining_table_id' => $t5['id'],
            'guest_count' => 3,
            'items' => [
                ['product_variant_unit_id' => 100, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method' => 'qris', 'amount' => 33000],
            ],
        ]);

        $this->assertSame('completed', $tx->status->value);
        $this->assertSame($t5['id'], (int) $tx->dining_table_id);
        $this->assertSame(3, (int) $tx->guest_count);
        $this->assertNotNull($tx->queue_number);

        // Table should be occupied (Terisi - Lunas) with active_transaction_id = null
        $tableRow = DB::table('dining_tables')->where('id', $t5['id'])->first();
        $this->assertSame('occupied', $tableRow->status);
        $this->assertNull($tableRow->active_transaction_id);
        $this->assertSame(3, (int) $tableRow->guest_count);

        // KDS ticket should be dispatched with table_code = 'T-05'
        $ticket = DB::table('kitchen_tickets')->where('sales_transaction_id', $tx->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame('T-05', $ticket->table_code);
        $this->assertSame('Meja 5', $ticket->table_name);
        $this->assertSame($tx->queue_number, $ticket->queue_number);
    }

    public function test_complete_held_sets_table_to_cleaning_when_auto_cleaning_enabled(): void
    {
        app(PosConfigResolver::class)->updateBranchSetting(1, [
            'auto_cleaning_after_payment' => true,
        ]);

        $indoor = $this->tableService->createArea(['branch_id' => 1, 'name' => 'VIP']);
        $t6 = $this->tableService->createTable([
            'branch_id' => 1,
            'dining_area_id' => $indoor->id,
            'code' => 'T-06',
            'name' => 'Meja 6',
            'capacity' => 4,
        ]);

        $order = $this->tableService->saveTableOrder($t6['id'], [
            'guest_count' => 2,
            'items' => [
                ['product_variant_unit_id' => 200, 'quantity' => 2],
            ],
        ]);

        $shift = $this->shiftService->open(['branch_id' => 1, 'opening_cash' => 50000]);
        $this->txService->completeHeld($order['transaction']->id, [
            'cashier_shift_id' => $shift->id,
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 25000],
            ],
        ]);

        $tableRow = DB::table('dining_tables')->where('id', $t6['id'])->first();
        $this->assertSame('cleaning', $tableRow->status);
        $this->assertNull($tableRow->active_transaction_id);

        // Staff marks cleaning table back to available
        $cleaned = $this->tableService->updateStatus($t6['id'], 'available');
        $this->assertSame('available', $cleaned['status']);
        $this->assertNull($cleaned['guest_count']);
    }

    public function test_move_table_syncs_active_kds_tickets_for_both_pay_first_and_open_bill_and_exposes_batch_kds_summary(): void
    {
        $area = $this->tableService->createArea(['branch_id' => 1, 'name' => 'Patio']);
        $t10 = $this->tableService->createTable(['branch_id' => 1, 'dining_area_id' => $area->id, 'code' => 'T-10', 'name' => 'Meja 10', 'capacity' => 4]);
        $t11 = $this->tableService->createTable(['branch_id' => 1, 'dining_area_id' => $area->id, 'code' => 'T-11', 'name' => 'Meja 11', 'capacity' => 4]);

        DB::table('kitchen_stations')->insert([
            'id' => 10,
            'merchant_id' => 1,
            'branch_id' => 1,
            'code' => 'BAR',
            'name' => 'Coffee Bar',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shift = $this->shiftService->open(['branch_id' => 1, 'opening_cash' => 100000]);

        // 1. Pay-First Dine-in on T-10 (guest paid upfront, active_transaction_id is null, KDS ticket is pending)
        $tx = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'status' => 'completed',
            'order_type' => 'dine_in',
            'dining_table_id' => $t10['id'],
            'guest_count' => 2,
            'items' => [
                ['product_variant_unit_id' => 100, 'quantity' => 1],
            ],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 35000],
            ],
        ]);

        // Verify batch KDS summary appears on T-10 in listByBranch
        $mapBefore = $this->tableService->listByBranch(1);
        $t10Before = collect($mapBefore['tables'])->firstWhere('id', $t10['id']);
        $this->assertSame('occupied', $t10Before['status']);
        $this->assertNull($t10Before['active_transaction_id']);
        $this->assertNotNull($t10Before['kds_summary']);
        $this->assertSame(1, $t10Before['kds_summary']['pending']);
        $this->assertSame(1, $t10Before['kds_summary']['total_active']);

        // 2. Guest moves from T-10 -> T-11 even though they already paid (Pay-First)
        $moved = $this->tableService->moveTable($t10['id'], $t11['id']);
        $this->assertSame('available', $moved['from_table']['status']);
        $this->assertNull($moved['from_table']['guest_count']);
        $this->assertSame('occupied', $moved['to_table']['status']);
        $this->assertNull($moved['to_table']['active_transaction_id']);
        $this->assertSame(2, $moved['to_table']['guest_count']);
        $this->assertSame(1, $moved['kds_tickets_updated']);

        // KDS ticket MUST now show table_code = 'T-11' so runner delivers to the new table
        $ticket = DB::table('kitchen_tickets')->where('sales_transaction_id', $tx->id)->first();
        $this->assertSame('T-11', $ticket->table_code);
        $this->assertSame('Meja 11', $ticket->table_name);

        // Verify batch KDS summary moved to T-11
        $mapAfter = $this->tableService->listByBranch(1);
        $t10After = collect($mapAfter['tables'])->firstWhere('id', $t10['id']);
        $t11After = collect($mapAfter['tables'])->firstWhere('id', $t11['id']);
        $this->assertNull($t10After['kds_summary']);
        $this->assertSame(1, $t11After['kds_summary']['total_active']);
    }

    public function test_open_bill_item_reduction_and_removal_auto_syncs_kds_and_attaches_sent_qty(): void
    {
        $area = $this->tableService->createArea(['branch_id' => 1, 'name' => 'Hall']);
        $t20 = $this->tableService->createTable(['branch_id' => 1, 'dining_area_id' => $area->id, 'code' => 'T-20', 'name' => 'Meja 20', 'capacity' => 4]);

        DB::table('kitchen_stations')->insert([
            'id' => 20,
            'merchant_id' => 1,
            'branch_id' => 1,
            'code' => 'KIT',
            'name' => 'Kitchen',
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Send 2 Nasi Goreng + 1 Es Teh to kitchen
        $order = $this->tableService->saveTableOrder($t20['id'], [
            'guest_count' => 2,
            'items' => [
                ['product_variant_unit_id' => 100, 'quantity' => 2],
                ['product_variant_unit_id' => 200, 'quantity' => 1],
            ],
        ]);

        // Verify SalesTransactionService::show() attaches sent_to_kitchen_qty
        $loaded = $this->txService->show($order['transaction']->id);
        $nasiItem = $loaded->items->firstWhere('product_id', 1);
        $tehItem = $loaded->items->firstWhere('product_id', 2);
        $this->assertSame(2.0, (float) $nasiItem->sent_to_kitchen_qty);
        $this->assertSame(1.0, (float) $tehItem->sent_to_kitchen_qty);

        // 2. Guest cancels Es Teh completely and reduces Nasi Goreng from 2 -> 1 (requires void_reason_code)
        $this->tableService->saveTableOrder($t20['id'], [
            'guest_count' => 2,
            'void_reason_code' => 'guest_cancelled',
            'void_reason_note' => 'Tamu ubah pesanan',
            'items' => [
                ['product_variant_unit_id' => 100, 'quantity' => 1],
            ],
        ]);

        $ticket = DB::table('kitchen_tickets')->where('sales_transaction_id', $order['transaction']->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame('pending', $ticket->status);

        $kdsNasi = DB::table('kitchen_ticket_items')
            ->where('kitchen_ticket_id', $ticket->id)
            ->where('product_id', 1)
            ->first();
        $kdsTeh = DB::table('kitchen_ticket_items')
            ->where('kitchen_ticket_id', $ticket->id)
            ->where('product_id', 2)
            ->first();

        $this->assertSame(1.0, (float) $kdsNasi->quantity);
        $this->assertSame('pending', $kdsNasi->status);
        $this->assertSame('voided', $kdsTeh->status);

        // Reloaded Open Bill shows updated sent_to_kitchen_qty = 1.0
        $reloaded = $this->txService->show($order['transaction']->id);
        $this->assertCount(1, $reloaded->items);
        $this->assertSame(1.0, (float) $reloaded->items->first()->sent_to_kitchen_qty);
    }

    private function createSchema(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('industry_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
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
            $table->string('type')->default('product');
            $table->boolean('track_stock')->default(true);
            $table->boolean('can_be_sold')->default(true);
            $table->boolean('is_taxable')->default(false);
            $table->decimal('tax_rate', 5, 2)->default(0);
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
            $table->unsignedBigInteger('unit_id');
            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->boolean('is_base')->default(true);
            $table->string('barcode')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('default_price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_variant_unit_id');
            $table->unsignedBigInteger('price_list_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('min_qty', 15, 4)->default(1);
            $table->decimal('max_qty', 15, 4)->nullable();
            $table->string('pricing_mode')->default('fixed');
            $table->decimal('price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
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
        Schema::create('dining_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 100);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('dining_area_id')->nullable();
            $table->string('code', 30);
            $table->string('name', 80);
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->string('shape', 20)->default('square');
            $table->string('status', 20)->default('available');
            $table->unsignedBigInteger('active_transaction_id')->nullable();
            $table->unsignedSmallInteger('guest_count')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('sales_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('doc_type', 20);
            $table->string('period', 16);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['merchant_id', 'doc_type', 'period']);
        });
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('shift_number', 50);
            $table->string('status', 20)->default('open');
            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('actual_cash', 15, 2)->nullable();
            $table->decimal('variance', 15, 2)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('cashier_shift_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('cashier_shift_id');
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255);
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
            $table->string('transaction_number', 50);
            $table->string('idempotency_key', 100)->nullable();
            $table->string('status', 20)->default('completed');
            $table->string('order_type', 20)->default('retail');
            $table->unsignedBigInteger('dining_table_id')->nullable();
            $table->unsignedSmallInteger('guest_count')->nullable();
            $table->string('queue_number', 20)->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('discount_type', 20)->default('none');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->decimal('service_charge_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);
            $table->string('hold_label', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('sales_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('sales_transaction_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('product_variant_unit_id');
            $table->string('product_name', 255);
            $table->string('variant_name', 255)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('unit_name', 100);
            $table->string('unit_code', 50)->nullable();
            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->decimal('quantity', 15, 4);
            $table->decimal('base_quantity', 15, 4);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('modifiers_total', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);
            $table->string('discount_type', 20)->default('none');
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('sales_transaction_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('sales_transaction_item_id');
            $table->unsignedBigInteger('modifier_group_id')->nullable();
            $table->unsignedBigInteger('modifier_option_id')->nullable();
            $table->string('modifier_group_name', 120);
            $table->string('modifier_option_name', 120);
            $table->decimal('price_adjustment', 15, 2)->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('sales_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('sales_transaction_id');
            $table->string('payment_method', 30);
            $table->decimal('amount', 15, 2);
            $table->string('reference_number', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_pos_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('default_ui_mode', 20)->nullable();
            $table->string('print_template', 30)->nullable();
            $table->decimal('service_charge_rate', 5, 2)->nullable();
            $table->boolean('allow_decimal_qty')->nullable();
            $table->boolean('enable_item_notes')->nullable();
            $table->boolean('barcode_fast_scan')->nullable();
            $table->boolean('require_customer')->nullable();
            $table->json('extra_config')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'branch_id']);
        });
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('code', 30);
            $table->string('name', 100);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('kitchen_station_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('kitchen_station_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();
        });
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('sales_transaction_id');
            $table->unsignedBigInteger('kitchen_station_id')->nullable();
            $table->string('ticket_number', 40);
            $table->string('order_type', 20)->default('dine_in');
            $table->string('queue_number', 20)->nullable();
            $table->string('table_code', 30)->nullable();
            $table->string('table_name', 80)->nullable();
            $table->string('customer_name', 120)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('kitchen_ticket_id');
            $table->unsignedBigInteger('sales_transaction_item_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('item_name', 255);
            $table->string('variant_name', 255)->nullable();
            $table->string('unit_name', 100)->nullable();
            $table->decimal('quantity', 15, 4);
            $table->json('modifiers_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }
}
