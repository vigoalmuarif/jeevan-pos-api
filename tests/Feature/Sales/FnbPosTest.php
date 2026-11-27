<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Product\Services\ModifierGroupService;
use Modules\Sales\Services\CashierShiftService;
use Modules\Sales\Services\PosCatalogService;
use Modules\Sales\Services\PosConfigResolver;
use Modules\Sales\Services\SalesTransactionService;
use Modules\User\Models\User;
use Tests\TestCase;

class FnbPosTest extends TestCase
{
    private Merchant $cafeMerchant;
    private ModifierGroupService $modifierService;
    private PosConfigResolver $configResolver;
    private PosCatalogService $catalogService;
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
            [
                'id' => 1,
                'name' => 'Kopi Senja',
                'industry_code' => 'cafe',
                'metadata' => json_encode(['inventory' => ['allow_negative_stock' => false]]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->cafeMerchant = Merchant::query()->findOrFail(1);
        MerchantContext::set($this->cafeMerchant);

        DB::table('users')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Barista A', 'email' => 'barista@test.id', 'password' => 'secret'],
        ]);

        $this->actingAs(User::query()->findOrFail(1), 'merchant');

        DB::table('warehouses')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Gudang Cafe', 'is_active' => true],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Cafe Pusat', 'primary_warehouse_id' => 1, 'is_main' => true, 'is_active' => true],
        ]);

        DB::table('units')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Cup', 'code' => 'CUP', 'is_active' => true],
        ]);

        // 3 menu items to also verify zero N+1 query scaling on catalog
        for ($i = 1; $i <= 3; $i++) {
            DB::table('products')->insert([
                'id' => $i,
                'merchant_id' => 1,
                'name' => "Kopi Menu {$i}",
                'type' => 'product',
                'track_stock' => false,
                'can_be_sold' => true,
                'is_taxable' => true,
                'tax_rate' => 10,
                'is_active' => true,
            ]);
            DB::table('product_variants')->insert([
                'id' => $i * 10,
                'merchant_id' => 1,
                'product_id' => $i,
                'name' => 'Iced',
                'sku' => "KOPI-{$i}",
                'is_active' => true,
            ]);
            DB::table('product_variant_units')->insert([
                'id' => $i * 100,
                'merchant_id' => 1,
                'product_variant_id' => $i * 10,
                'unit_id' => 1,
                'conversion_value' => 1,
                'is_base' => true,
                'cost' => 8000,
                'default_price' => 25000,
                'is_active' => true,
            ]);
            DB::table('branch_active_products')->insert([
                'merchant_id' => 1,
                'branch_id' => 1,
                'product_variant_id' => $i * 10,
                'source' => 'assortment',
                'resolved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->modifierService = app(ModifierGroupService::class);
        $this->configResolver = app(PosConfigResolver::class);
        $this->catalogService = app(PosCatalogService::class);
        $this->shiftService = app(CashierShiftService::class);
        $this->txService = app(SalesTransactionService::class);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        Cache::flush();
        parent::tearDown();
    }

    public function test_catalog_batch_loads_modifiers_without_n_plus_one_queries(): void
    {
        $sugarGroup = $this->modifierService->create([
            'name' => 'Level Gula',
            'selection_type' => 'single',
            'is_required' => true,
            'min_select' => 1,
            'max_select' => 1,
            'options' => [
                ['name' => 'Normal Sugar', 'price_adjustment' => 0, 'is_default' => true],
                ['name' => 'Less Sugar', 'price_adjustment' => 0],
            ],
        ]);

        $toppingGroup = $this->modifierService->create([
            'name' => 'Extra Topping',
            'selection_type' => 'multiple',
            'is_required' => false,
            'max_select' => 3,
            'options' => [
                ['name' => 'Extra Shot', 'price_adjustment' => 5000],
                ['name' => 'Oat Milk', 'price_adjustment' => 6000],
            ],
        ]);

        foreach ([1, 2, 3] as $productId) {
            $this->modifierService->syncProductGroups($productId, [$sugarGroup->id, $toppingGroup->id]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $catalog = $this->catalogService->catalog(['branch_id' => 1]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(3, $catalog['data']);
        foreach ($catalog['data'] as $item) {
            $this->assertCount(2, $item['modifier_groups']);
            $this->assertSame('Level Gula', $item['modifier_groups'][0]['name']);
            $this->assertCount(2, $item['modifier_groups'][0]['options']);
        }

        // Verify constant number of modifier data queries (1 for product_modifier_groups + 1 for modifier_options = 2, NOT 3*2)
        $modifierQueries = array_filter(
            $queries,
            fn (array $q) => str_contains($q['query'], 'modifier_') && ! str_contains($q['query'], 'sqlite_master'),
        );
        $this->assertSame(2, count($modifierQueries), 'Modifier queries must be batched into exactly 2 queries (zero N+1).');
    }

    public function test_checkout_calculates_modifiers_service_charge_pb1_and_queue_number(): void
    {
        // Configure 5% service charge on branch 1
        $this->configResolver->updateBranchSetting(1, [
            'service_charge_rate' => 5.0,
        ]);

        $sugarGroup = $this->modifierService->create([
            'name' => 'Level Gula',
            'selection_type' => 'single',
            'is_required' => true,
            'options' => [
                ['name' => 'Less Sugar', 'price_adjustment' => 0],
            ],
        ]);
        $toppingGroup = $this->modifierService->create([
            'name' => 'Topping',
            'selection_type' => 'multiple',
            'is_required' => false,
            'options' => [
                ['name' => 'Extra Shot', 'price_adjustment' => 5000],
            ],
        ]);
        $this->modifierService->syncProductGroups(1, [$sugarGroup->id, $toppingGroup->id]);

        $lessSugarId = $sugarGroup->options->first()->id;
        $extraShotId = $toppingGroup->options->first()->id;

        $shift = $this->shiftService->open(['branch_id' => 1, 'opening_cash' => 100000]);

        // Dine-in: 2 x (25.000 + 5.000) = 60.000
        // Service Charge 5% = 3.000
        // PB1 10% = (60.000 + 3.000) * 10% = 6.300
        // Grand Total = 60.000 + 3.000 + 6.300 = 69.300
        $tx1 = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'status' => 'completed',
            'order_type' => 'dine_in',
            'items' => [
                [
                    'product_variant_unit_id' => 100,
                    'quantity' => 2,
                    'notes' => 'Es pisah',
                    'modifiers' => [
                        ['modifier_option_id' => $lessSugarId, 'quantity' => 1],
                        ['modifier_option_id' => $extraShotId, 'quantity' => 1],
                    ],
                ],
            ],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 70000],
            ],
        ]);

        $this->assertSame('dine_in', $tx1->order_type->value);
        $this->assertSame('Q-001', $tx1->queue_number);
        $this->assertSame(60000.0, (float) $tx1->subtotal);
        $this->assertSame(5.0, (float) $tx1->service_charge_rate);
        $this->assertSame(3000.0, (float) $tx1->service_charge_amount);
        $this->assertSame(6300.0, (float) $tx1->tax_amount);
        $this->assertSame(69300.0, (float) $tx1->grand_total);
        $this->assertSame(700.0, (float) $tx1->change_amount);
        $this->assertCount(1, $tx1->items);
        $this->assertSame('Es pisah', $tx1->items->first()->notes);
        $this->assertCount(2, $tx1->items->first()->modifiers);

        // Takeaway: NO service charge (0%), PB1 10% of 30.000 = 3.000 -> Grand Total = 33.000, Queue = Q-002
        $tx2 = $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'status' => 'completed',
            'order_type' => 'takeaway',
            'items' => [
                [
                    'product_variant_unit_id' => 100,
                    'quantity' => 1,
                    'modifiers' => [
                        ['modifier_option_id' => $lessSugarId, 'quantity' => 1],
                        ['modifier_option_id' => $extraShotId, 'quantity' => 1],
                    ],
                ],
            ],
            'payments' => [
                ['payment_method' => 'qris', 'amount' => 33000],
            ],
        ]);

        $this->assertSame('takeaway', $tx2->order_type->value);
        $this->assertSame('Q-002', $tx2->queue_number);
        $this->assertSame(0.0, (float) $tx2->service_charge_rate);
        $this->assertSame(0.0, (float) $tx2->service_charge_amount);
        $this->assertSame(3000.0, (float) $tx2->tax_amount);
        $this->assertSame(33000.0, (float) $tx2->grand_total);
    }

    public function test_rejects_checkout_when_required_modifier_is_missing(): void
    {
        $sugarGroup = $this->modifierService->create([
            'name' => 'Level Gula',
            'selection_type' => 'single',
            'is_required' => true,
            'options' => [
                ['name' => 'Normal', 'price_adjustment' => 0],
            ],
        ]);
        $this->modifierService->syncProductGroups(1, [$sugarGroup->id]);
        $shift = $this->shiftService->open(['branch_id' => 1, 'opening_cash' => 50000]);

        $this->expectException(ValidationException::class);

        $this->txService->create([
            'branch_id' => 1,
            'cashier_shift_id' => $shift->id,
            'status' => 'completed',
            'order_type' => 'dine_in',
            'items' => [
                [
                    'product_variant_unit_id' => 100,
                    'quantity' => 1,
                ],
            ],
            'payments' => [
                ['payment_method' => 'cash', 'amount' => 30000],
            ],
        ]);
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
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->default(1);
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
            $table->unsignedBigInteger('merchant_id')->default(1);
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
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('avg_cost', 15, 4)->default(0);
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
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name', 120);
            $table->string('selection_type', 20)->default('single');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('min_select')->default(0);
            $table->unsignedSmallInteger('max_select')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('modifier_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('modifier_group_id');
            $table->string('name', 120);
            $table->decimal('price_adjustment', 15, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('modifier_group_id');
            $table->integer('sort_order')->default(0);
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
