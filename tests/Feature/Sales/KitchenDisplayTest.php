<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Sales\Services\KitchenDisplayService;
use Modules\Sales\Services\TableManagementService;
use Modules\User\Models\User;
use Tests\TestCase;

class KitchenDisplayTest extends TestCase
{
    private Merchant $cafeMerchant;
    private KitchenDisplayService $kdsService;
    private TableManagementService $tableService;

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
            'name' => 'Cafe KDS',
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
            'name' => 'Kasir Cafe',
            'email' => 'kds@test.id',
            'password' => 'secret',
        ]);
        $this->actingAs(User::query()->findOrFail(1), 'merchant');

        DB::table('modules')->insert([
            ['id' => 1, 'code' => 'sales', 'name' => 'Sales', 'is_active' => true],
            ['id' => 2, 'code' => 'table_management', 'name' => 'Table Management', 'is_active' => true],
            ['id' => 3, 'code' => 'kitchen_display', 'name' => 'Kitchen Display', 'is_active' => true],
        ]);
        DB::table('merchant_modules')->insert([
            ['merchant_id' => 1, 'module_id' => 1, 'is_active' => true],
            ['merchant_id' => 1, 'module_id' => 2, 'is_active' => true],
            ['merchant_id' => 1, 'module_id' => 3, 'is_active' => true],
        ]);

        DB::table('warehouses')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'name' => 'Gudang Cafe',
            'is_active' => true,
        ]);
        DB::table('branches')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'name' => 'Cabang Pusat',
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
        DB::table('categories')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Makanan'],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Minuman'],
        ]);
        DB::table('products')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Mie Goreng', 'type' => 'product', 'track_stock' => false, 'can_be_sold' => true, 'is_taxable' => true, 'tax_rate' => 10, 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Matcha Latte', 'type' => 'product', 'track_stock' => false, 'can_be_sold' => true, 'is_taxable' => true, 'tax_rate' => 10, 'is_active' => true],
        ]);
        DB::table('product_categories')->insert([
            ['product_id' => 1, 'category_id' => 1, 'is_primary' => true],
            ['product_id' => 2, 'category_id' => 2, 'is_primary' => true],
        ]);
        DB::table('product_variants')->insert([
            ['id' => 10, 'merchant_id' => 1, 'product_id' => 1, 'name' => 'Pedas', 'sku' => 'MIE-01', 'is_active' => true],
            ['id' => 20, 'merchant_id' => 1, 'product_id' => 2, 'name' => 'Iced', 'sku' => 'MTC-01', 'is_active' => true],
        ]);
        DB::table('product_variant_units')->insert([
            ['id' => 100, 'merchant_id' => 1, 'product_variant_id' => 10, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'cost' => 10000, 'default_price' => 25000, 'is_active' => true],
            ['id' => 200, 'merchant_id' => 1, 'product_variant_id' => 20, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'cost' => 9000, 'default_price' => 28000, 'is_active' => true],
        ]);
        DB::table('branch_active_products')->insert([
            ['merchant_id' => 1, 'branch_id' => 1, 'product_variant_id' => 10, 'source' => 'assortment', 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['merchant_id' => 1, 'branch_id' => 1, 'product_variant_id' => 20, 'source' => 'assortment', 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->kdsService = app(KitchenDisplayService::class);
        $this->tableService = app(TableManagementService::class);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        Cache::flush();
        parent::tearDown();
    }

    public function test_routes_by_station_and_sends_incremental_kot_on_open_bill_update(): void
    {
        $kitchen = $this->kdsService->createStation([
            'branch_id' => 1,
            'code' => 'KITCHEN',
            'name' => 'Dapur Makanan',
            'is_default' => true,
            'category_ids' => [1],
        ]);
        $bar = $this->kdsService->createStation([
            'branch_id' => 1,
            'code' => 'BAR',
            'name' => 'Bar Minuman',
            'category_ids' => [2],
        ]);

        $t1 = $this->tableService->createTable(['branch_id' => 1, 'code' => 'T-01', 'name' => 'Meja 1']);

        // 1. Pesanan awal di Meja T-01: 1 Mie Goreng (Kitchen) + 1 Matcha Latte (Bar)
        $this->tableService->saveTableOrder($t1['id'], [
            'guest_count' => 2,
            'items' => [
                ['product_variant_unit_id' => 100, 'quantity' => 1, 'notes' => 'Tanpa daun bawang'],
                ['product_variant_unit_id' => 200, 'quantity' => 1],
            ],
        ]);

        $kitchenTickets = $this->kdsService->listTickets(1, $kitchen['id']);
        $barTickets = $this->kdsService->listTickets(1, $bar['id']);

        $this->assertCount(1, $kitchenTickets);
        $this->assertCount(1, $barTickets);
        $this->assertSame('T-01', $kitchenTickets[0]['table_code']);
        $this->assertSame('Mie Goreng', $kitchenTickets[0]['items'][0]['item_name']);
        $this->assertSame('Matcha Latte', $barTickets[0]['items'][0]['item_name']);

        // 2. Tambah pesanan susulan di Meja T-01: Matcha Latte menjadi 2 (+1 delta untuk Bar saja!)
        $this->tableService->saveTableOrder($t1['id'], [
            'guest_count' => 2,
            'items' => [
                ['product_variant_unit_id' => 100, 'quantity' => 1, 'notes' => 'Tanpa daun bawang'],
                ['product_variant_unit_id' => 200, 'quantity' => 2],
            ],
        ]);

        // Kitchen tetap 1 tiket (tidak dikirim ulang), Bar bertambah 1 tiket baru dengan qty delta = 1!
        $this->assertCount(1, $this->kdsService->listTickets(1, $kitchen['id']));
        $barTicketsAfter = $this->kdsService->listTickets(1, $bar['id']);
        $this->assertCount(2, $barTicketsAfter);
        $this->assertSame(1.0, (float) $barTicketsAfter[1]['items'][0]['quantity']);

        // 3. Progres status tiket: pending -> preparing -> ready -> served
        $ticketId = $kitchenTickets[0]['id'];
        $prep = $this->kdsService->updateTicketStatus($ticketId, 'preparing');
        $this->assertSame('preparing', $prep['status']);
        $this->assertNotNull($prep['started_at']);

        $ready = $this->kdsService->updateTicketStatus($ticketId, 'ready');
        $this->assertSame('ready', $ready['status']);
        $this->assertNotNull($ready['ready_at']);

        $served = $this->kdsService->updateTicketStatus($ticketId, 'served');
        $this->assertSame('served', $served['status']);
        $this->assertNotNull($served['served_at']);

        // Tiket served tidak lagi tampil di daftar aktif default
        $this->assertCount(0, $this->kdsService->listTickets(1, $kitchen['id']));
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
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('merchant_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('module_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();
        });
        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('module_id');
            $table->timestamps();
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
        Schema::create('sales_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('doc_type', 20);
            $table->string('period', 16);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['merchant_id', 'doc_type', 'period']);
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
    }
}
