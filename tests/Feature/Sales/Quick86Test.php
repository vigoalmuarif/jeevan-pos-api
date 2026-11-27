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
use Tests\TestCase;

class Quick86Test extends TestCase
{
    private Merchant $merchant;
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
            ['id' => 1, 'name' => 'Kopi Nusantara', 'industry_code' => 'cafe', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Cafe Lain', 'industry_code' => 'cafe', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Gudang Pusat', 'is_active' => true],
        ]);
        DB::table('branches')->insert([
            ['id' => 10, 'merchant_id' => 1, 'name' => 'Cafe Pusat', 'primary_warehouse_id' => 1, 'is_main' => true, 'is_active' => true],
            ['id' => 20, 'merchant_id' => 2, 'name' => 'Cafe Lain Pusat', 'primary_warehouse_id' => null, 'is_main' => true, 'is_active' => true],
        ]);
        DB::table('units')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Pcs', 'code' => 'PCS'],
        ]);
        DB::table('products')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Croissant', 'track_stock' => false, 'can_be_sold' => true, 'is_taxable' => false, 'tax_rate' => 0, 'is_active' => true],
            ['id' => 2, 'merchant_id' => 2, 'name' => 'Foreign Menu', 'track_stock' => false, 'can_be_sold' => true, 'is_taxable' => false, 'tax_rate' => 0, 'is_active' => true],
        ]);
        DB::table('product_variants')->insert([
            ['id' => 101, 'merchant_id' => 1, 'product_id' => 1, 'name' => 'Butter', 'sku' => 'CRS-01', 'is_active' => true],
            ['id' => 201, 'merchant_id' => 2, 'product_id' => 2, 'name' => 'Regular', 'sku' => 'FRG-01', 'is_active' => true],
        ]);
        DB::table('product_variant_units')->insert([
            ['id' => 1001, 'merchant_id' => 1, 'product_variant_id' => 101, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'cost' => 10000, 'default_price' => 25000, 'is_active' => true],
        ]);
        DB::table('branch_active_products')->insert([
            ['merchant_id' => 1, 'branch_id' => 10, 'product_variant_id' => 101],
        ]);

        $this->merchant = Merchant::query()->findOrFail(1);
        $this->catalogService = app(PosCatalogService::class);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        Cache::flush();
        parent::tearDown();
    }

    public function test_toggles_quick_86_status_and_reflects_in_catalog_and_sold_out_list(): void
    {
        MerchantContext::set($this->merchant);

        // Initially available
        $initial = $this->catalogService->listSoldOutMenu(10);
        $this->assertCount(1, $initial);
        $this->assertFalse($initial[0]['is_sold_out']);

        // Mark Sold Out (86)
        $toggled = $this->catalogService->toggleSoldOut(10, 101, true, 'Adonan habis');
        $this->assertTrue($toggled['is_sold_out']);
        $this->assertSame(now()->toDateString(), $toggled['sold_out_date']);

        $after86 = $this->catalogService->listSoldOutMenu(10, null, true);
        $this->assertCount(1, $after86);
        $this->assertTrue($after86[0]['is_sold_out']);
        $this->assertSame('Adonan habis', $after86[0]['reason']);

        // Unmark Sold Out -> available again
        $cleared = $this->catalogService->toggleSoldOut(10, 101, false);
        $this->assertFalse($cleared['is_sold_out']);
        $this->assertCount(0, $this->catalogService->listSoldOutMenu(10, null, true));
    }

    public function test_rejects_cross_merchant_quick_86_toggle(): void
    {
        MerchantContext::set($this->merchant);

        $this->expectException(ValidationException::class);
        // Variant 201 belongs to Merchant 2
        $this->catalogService->toggleSoldOut(10, 201, true);
    }

    private function createSchema(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('industry_code')->nullable();
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
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
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
        Schema::create('branch_active_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->timestamps();
        });
        Schema::create('branch_sold_out_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->date('sold_out_date')->nullable();
            $table->string('reason', 120)->nullable();
            $table->unsignedBigInteger('marked_by')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'branch_id', 'product_variant_id']);
        });
    }
}
