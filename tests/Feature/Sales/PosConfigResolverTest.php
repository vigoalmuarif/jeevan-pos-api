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
use Modules\Sales\Services\PosCatalogService;
use Modules\Sales\Services\PosConfigResolver;
use Tests\TestCase;

class PosConfigResolverTest extends TestCase
{
    private Merchant $cafeMerchant;
    private Merchant $buildingMerchant;
    private PosConfigResolver $resolver;
    private PosCatalogService $catalogService;

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
                'name' => 'Kopi Nusantara',
                'industry_code' => 'cafe',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'name' => 'TB Maju Jaya',
                'industry_code' => 'building_material',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('branches')->insert([
            ['id' => 10, 'merchant_id' => 1, 'name' => 'Cafe Pusat', 'primary_warehouse_id' => null, 'is_main' => true, 'is_active' => true],
            ['id' => 11, 'merchant_id' => 1, 'name' => 'Cafe Express Booth', 'primary_warehouse_id' => null, 'is_main' => false, 'is_active' => true],
            ['id' => 20, 'merchant_id' => 2, 'name' => 'TB Utama', 'primary_warehouse_id' => null, 'is_main' => true, 'is_active' => true],
        ]);

        DB::table('modules')->insert([
            ['id' => 1, 'code' => 'sales', 'name' => 'Sales', 'is_active' => true],
            ['id' => 2, 'code' => 'table_management', 'name' => 'Table Management', 'is_active' => true],
            ['id' => 3, 'code' => 'kitchen_display', 'name' => 'Kitchen Display', 'is_active' => true],
            ['id' => 4, 'code' => 'material_calculation', 'name' => 'Material Calculation', 'is_active' => true],
            ['id' => 5, 'code' => 'delivery_order', 'name' => 'Delivery Order', 'is_active' => true],
        ]);

        // Cafe merchant hanya punya sales + table_management (TIDAK punya kitchen_display)
        DB::table('merchant_modules')->insert([
            ['merchant_id' => 1, 'module_id' => 1, 'is_active' => true],
            ['merchant_id' => 1, 'module_id' => 2, 'is_active' => true],
            ['merchant_id' => 1, 'module_id' => 3, 'is_active' => false],
            // Building material merchant punya sales + delivery_order (TIDAK punya material_calculation)
            ['merchant_id' => 2, 'module_id' => 1, 'is_active' => true],
            ['merchant_id' => 2, 'module_id' => 5, 'is_active' => true],
        ]);

        $this->cafeMerchant = Merchant::query()->findOrFail(1);
        $this->buildingMerchant = Merchant::query()->findOrFail(2);
        $this->resolver = app(PosConfigResolver::class);
        $this->catalogService = app(PosCatalogService::class);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        Cache::flush();
        parent::tearDown();
    }

    public function test_resolves_industry_preset_and_strictly_enforces_module_entitlements(): void
    {
        MerchantContext::set($this->cafeMerchant);

        $config = $this->resolver->resolveForBranch(10);

        $this->assertSame('cafe', $config['industry_preset']);
        $this->assertSame('grid', $config['default_ui_mode']);
        $this->assertSame('thermal_80mm', $config['print_template']);
        $this->assertTrue($config['capabilities']['item_notes']);
        $this->assertTrue($config['capabilities']['order_type']);
        $this->assertTrue($config['capabilities']['modifiers']);
        // Entitled module -> true
        $this->assertTrue($config['capabilities']['table_management']);
        // Non-entitled module -> MUST be false even though cafe preset wants it
        $this->assertFalse($config['capabilities']['kitchen_display']);
        $this->assertFalse($config['capabilities']['material_calculator']);
        $this->assertFalse($config['capabilities']['delivery_order']);

        // Switch to Building Material merchant
        MerchantContext::set($this->buildingMerchant);
        $tbConfig = $this->resolver->resolveForBranch(20);

        $this->assertSame('building_material', $tbConfig['industry_preset']);
        $this->assertSame('list', $tbConfig['default_ui_mode']);
        $this->assertSame('invoice_a5', $tbConfig['print_template']);
        $this->assertTrue($tbConfig['capabilities']['decimal_qty']);
        $this->assertTrue($tbConfig['capabilities']['item_notes']);
        $this->assertTrue($tbConfig['capabilities']['delivery_order']);
        $this->assertFalse($tbConfig['capabilities']['material_calculator']);
    }

    public function test_supports_independent_per_branch_overrides_within_same_merchant(): void
    {
        MerchantContext::set($this->cafeMerchant);

        // Override hanya cabang 11 (Cafe Express Booth) menjadi thermal_58mm, decimal_qty=true, service_charge_rate=5
        $updated = $this->resolver->updateBranchSetting(11, [
            'default_ui_mode' => 'list',
            'print_template' => 'thermal_58mm',
            'service_charge_rate' => 5.0,
            'allow_decimal_qty' => true,
            'enable_item_notes' => false,
        ]);

        $this->assertTrue($updated['has_branch_override']);
        $this->assertSame('list', $updated['default_ui_mode']);
        $this->assertSame('thermal_58mm', $updated['print_template']);
        $this->assertSame(5.0, $updated['service_charge_rate']);
        $this->assertTrue($updated['capabilities']['decimal_qty']);
        $this->assertFalse($updated['capabilities']['item_notes']);

        // Cabang 10 (Cafe Pusat) tetap memakai preset default
        $options = $this->catalogService->options();
        $this->assertArrayHasKey('pos_config', $options);
        $this->assertArrayHasKey('branch_pos_configs', $options);

        $branch10 = $options['branch_pos_configs'][10];
        $branch11 = $options['branch_pos_configs'][11];

        $this->assertFalse($branch10['has_branch_override']);
        $this->assertSame('grid', $branch10['default_ui_mode']);
        $this->assertSame('thermal_80mm', $branch10['print_template']);
        $this->assertFalse($branch10['capabilities']['decimal_qty']);
        $this->assertTrue($branch10['capabilities']['item_notes']);

        $this->assertTrue($branch11['has_branch_override']);
        $this->assertSame('list', $branch11['default_ui_mode']);
        $this->assertSame('thermal_58mm', $branch11['print_template']);
        $this->assertTrue($branch11['capabilities']['decimal_qty']);
        $this->assertFalse($branch11['capabilities']['item_notes']);
    }

    public function test_takeaway_only_branch_forces_pay_first_and_disables_tables_and_service_charge(): void
    {
        MerchantContext::set($this->cafeMerchant);

        // Configure branch 11 (Cafe Express Booth) as Takeaway-Only even if caller tries pay_later
        $booth = $this->resolver->updateBranchSetting(11, [
            'enabled_order_types' => ['takeaway'],
            'payment_flow' => 'pay_later',
            'auto_cleaning_after_payment' => true,
        ]);

        $this->assertSame(['takeaway'], $booth['enabled_order_types']);
        $this->assertSame('pay_first', $booth['payment_flow']);
        $this->assertFalse($booth['capabilities']['table_management']);
        $this->assertFalse($booth['capabilities']['service_charge']);
        $this->assertTrue($booth['auto_cleaning_after_payment']);

        // Branch 10 (Cafe Pusat) remains unaffected with full Dine-in + Flexible flow
        $pusat = $this->resolver->resolveForBranch(10);
        $this->assertSame(['dine_in', 'takeaway', 'delivery'], $pusat['enabled_order_types']);
        $this->assertSame('flexible', $pusat['payment_flow']);
        $this->assertTrue($pusat['capabilities']['table_management']);
        $this->assertFalse($pusat['auto_cleaning_after_payment']);

        // Resetting branch 11 restores default cafe preset
        $reset = $this->resolver->updateBranchSetting(11, [
            'enabled_order_types' => null,
            'payment_flow' => null,
            'auto_cleaning_after_payment' => null,
        ]);
        $this->assertSame(['dine_in', 'takeaway', 'delivery'], $reset['enabled_order_types']);
        $this->assertSame('flexible', $reset['payment_flow']);
        $this->assertTrue($reset['capabilities']['table_management']);
        $this->assertFalse($reset['auto_cleaning_after_payment']);
    }

    public function test_supports_channel_price_lists_mapping_and_enforces_merchant_isolation(): void
    {
        DB::table('price_lists')->insert([
            ['id' => 101, 'merchant_id' => 1, 'code' => 'OJOL', 'name' => 'Harga GoFood/GrabFood', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 201, 'merchant_id' => 2, 'code' => 'M2-PL', 'name' => 'Merchant 2 Price List', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        MerchantContext::set($this->cafeMerchant);

        $updated = $this->resolver->updateBranchSetting(10, [
            'channel_price_lists' => [
                'dine_in' => null,
                'takeaway' => null,
                'delivery' => 101,
            ],
        ]);

        $this->assertNull($updated['channel_price_lists']['dine_in']);
        $this->assertNull($updated['channel_price_lists']['takeaway']);
        $this->assertSame(101, $updated['channel_price_lists']['delivery']);

        // Options returns merchant's active price_lists
        $options = $this->catalogService->options(10);
        $this->assertCount(1, $options['price_lists']);
        $this->assertSame(101, $options['price_lists'][0]['id']);

        // Cross-merchant price_list_id MUST be rejected
        $this->expectException(ValidationException::class);
        $this->resolver->updateBranchSetting(10, [
            'channel_price_lists' => [
                'delivery' => 201,
            ],
        ]);
    }

    public function test_supports_multi_channel_delivery_price_lists_and_enforces_merchant_isolation(): void
    {
        DB::table('price_lists')->insert([
            ['id' => 101, 'merchant_id' => 1, 'code' => 'GOFOOD', 'name' => 'GoFood', 'default_markup_percent' => 20.0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'merchant_id' => 1, 'code' => 'SHOPEEFOOD', 'name' => 'ShopeeFood', 'default_markup_percent' => 25.0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 201, 'merchant_id' => 2, 'code' => 'M2-PL', 'name' => 'Merchant 2 PL', 'default_markup_percent' => 15.0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        MerchantContext::set($this->cafeMerchant);

        $updated = $this->resolver->updateBranchSetting(10, [
            'delivery_channels' => [
                ['code' => 'gofood', 'name' => 'GoFood', 'price_list_id' => 101],
                ['code' => 'grabfood', 'name' => 'GrabFood', 'price_list_id' => 101], // Same price list as GoFood
                ['code' => 'shopeefood', 'name' => 'ShopeeFood', 'price_list_id' => 102], // Different price list
                ['code' => 'own_delivery', 'name' => 'Kurir Toko', 'price_list_id' => null], // Standard price
            ],
        ]);

        $this->assertCount(4, $updated['delivery_channels']);
        $this->assertSame('gofood', $updated['delivery_channels'][0]['code']);
        $this->assertSame(101, $updated['delivery_channels'][0]['price_list_id']);
        $this->assertSame(101, $updated['delivery_channels'][1]['price_list_id']);
        $this->assertSame(102, $updated['delivery_channels'][2]['price_list_id']);
        $this->assertNull($updated['delivery_channels'][3]['price_list_id']);

        $options = $this->catalogService->options(10);
        $this->assertCount(2, $options['price_lists']);
        $this->assertSame(20.0, $options['price_lists'][0]['default_markup_percent']);

        // Cross-merchant price_list_id inside delivery_channels MUST be rejected
        $this->expectException(ValidationException::class);
        $this->resolver->updateBranchSetting(10, [
            'delivery_channels' => [
                ['code' => 'gofood', 'name' => 'GoFood', 'price_list_id' => 201],
            ],
        ]);
    }

    public function test_prevents_cross_merchant_branch_setting_modification(): void
    {
        MerchantContext::set($this->cafeMerchant);

        $this->expectException(ValidationException::class);

        // Branch 20 belongs to Merchant 2 (buildingMerchant)
        $this->resolver->updateBranchSetting(20, [
            'print_template' => 'invoice_a5',
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

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
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

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('code', 50)->nullable();
            $table->string('name', 120);
            $table->decimal('default_markup_percent', 6, 2)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
}
