<?php

namespace Tests\Feature\Product;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Product\Services\ProductVariantUnitService;
use Tests\TestCase;

class ProductVariantUnitIntegrityTest extends TestCase
{
    private Merchant $merchantA;
    private Merchant $merchantB;
    private int $additionalVariantUnitId = 2;

    protected function setUp(): void
    {
        parent::setUp();
        MerchantContext::clear();
        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('These tests require the isolated in-memory SQLite database from phpunit.xml.');
        }

        $this->createSchema();

        DB::table('merchants')->insert([
            ['id' => 1, 'name' => 'Merchant A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Merchant B', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->merchantA = Merchant::query()->findOrFail(1);
        $this->merchantB = Merchant::query()->findOrFail(2);
        MerchantContext::set($this->merchantA);

        DB::table('units')->insert([
            ['id' => 1, 'merchant_id' => null, 'name' => 'Pcs', 'code' => 'PCS', 'is_active' => true],
            ['id' => 2, 'merchant_id' => null, 'name' => 'Box', 'code' => 'BOX', 'is_active' => true],
        ]);

        DB::table('products')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'name' => 'Kopi Arabika',
            'type' => 'product',
            'track_stock' => true,
            'is_active' => true,
        ]);
        DB::table('product_variants')->insert([
            'id' => 1,
            'merchant_id' => 1,
            'product_id' => 1,
            'name' => '250g',
            'sku' => 'KOPI-250',
            'is_default' => true,
            'is_active' => true,
        ]);
        DB::table('product_unit_conversions')->insert([
            ['id' => 1, 'merchant_id' => 1, 'product_id' => 1, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true],
            ['id' => 2, 'merchant_id' => 1, 'product_id' => 1, 'unit_id' => 2, 'conversion_value' => 12, 'is_base' => false],
        ]);
        DB::table('product_variant_units')->insert([
            [
                'id' => 1,
                'merchant_id' => 1,
                'product_variant_id' => 1,
                'unit_id' => 1,
                'conversion_value' => 1,
                'is_base' => true,
                'cost' => 10000,
                'default_price' => 15000,
                'is_active' => true,
            ],
            [
                'id' => $this->additionalVariantUnitId,
                'merchant_id' => 1,
                'product_variant_id' => 1,
                'unit_id' => 2,
                'conversion_value' => 12,
                'is_base' => false,
                'cost' => 115000,
                'default_price' => 170000,
                'is_active' => true,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    public function test_allows_changing_conversion_value_when_no_stock_mutations_exist(): void
    {
        $service = app(ProductVariantUnitService::class);
        $updated = $service->update($this->additionalVariantUnitId, [
            'conversion_value' => 24,
            'default_price' => 320000,
        ]);

        $this->assertEquals(24.0, (float) $updated->conversion_value);
        $this->assertEquals(320000.0, (float) $updated->default_price);
        $this->assertEquals(
            24.0,
            (float) DB::table('product_unit_conversions')->where('id', 2)->value('conversion_value')
        );
    }

    public function test_blocks_changing_conversion_value_when_stock_mutations_exist(): void
    {
        DB::table('stock_mutations')->insert([
            'merchant_id' => 1,
            'warehouse_id' => 1,
            'product_variant_id' => 1,
            'mutation_type' => 'opening_stock',
            'quantity' => 12,
            'quantity_before' => 0,
            'quantity_after' => 12,
            'unit_cost' => 10000,
            'total_cost' => 120000,
            'avg_cost_after' => 10000,
        ]);

        $service = app(ProductVariantUnitService::class);

        try {
            $service->update($this->additionalVariantUnitId, [
                'conversion_value' => 24,
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('conversion_value', $e->errors());
        }

        // Pastikan rasio konversi tidak berubah di database
        $this->assertEquals(
            12.0,
            (float) DB::table('product_unit_conversions')->where('id', 2)->value('conversion_value')
        );
    }

    public function test_allows_updating_price_and_barcode_with_unchanged_conversion_when_stock_mutations_exist(): void
    {
        DB::table('stock_mutations')->insert([
            'merchant_id' => 1,
            'warehouse_id' => 1,
            'product_variant_id' => 1,
            'mutation_type' => 'opening_stock',
            'quantity' => 12,
            'quantity_before' => 0,
            'quantity_after' => 12,
            'unit_cost' => 10000,
            'total_cost' => 120000,
            'avg_cost_after' => 10000,
        ]);

        $service = app(ProductVariantUnitService::class);
        $updated = $service->update($this->additionalVariantUnitId, [
            'conversion_value' => 12,
            'barcode' => '8991234567890',
            'default_price' => 175000,
        ]);

        $this->assertEquals(12.0, (float) $updated->conversion_value);
        $this->assertSame('8991234567890', $updated->barcode);
        $this->assertEquals(175000.0, (float) $updated->default_price);
    }

    public function test_enforces_merchant_isolation_for_unit_conversion_guard(): void
    {
        // Mutasi stok milik Merchant B tidak boleh memblokir Merchant A
        DB::table('stock_mutations')->insert([
            'merchant_id' => 2,
            'warehouse_id' => 2,
            'product_variant_id' => 1,
            'mutation_type' => 'opening_stock',
            'quantity' => 5,
            'quantity_before' => 0,
            'quantity_after' => 5,
            'unit_cost' => 10000,
            'total_cost' => 50000,
            'avg_cost_after' => 10000,
        ]);

        $service = app(ProductVariantUnitService::class);
        $updated = $service->update($this->additionalVariantUnitId, [
            'conversion_value' => 10,
        ]);
        $this->assertEquals(10.0, (float) $updated->conversion_value);

        // Merchant B tidak dapat mengubah unit milik Merchant A
        MerchantContext::set($this->merchantB);
        $this->expectException(ModelNotFoundException::class);
        $service->update($this->additionalVariantUnitId, [
            'conversion_value' => 5,
        ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('stock_mutations');
        Schema::dropIfExists('product_variant_units');
        Schema::dropIfExists('product_unit_conversions');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('merchants');

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->string('name');
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->string('type')->default('product');
            $table->boolean('track_stock')->default(true);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('name')->nullable();
            $table->string('sku');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id');
            $table->decimal('conversion_value', 18, 4)->default(1);
            $table->boolean('is_base')->default(false);
            $table->timestamps();
        });

        Schema::create('product_variant_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('unit_id');
            $table->decimal('conversion_value', 18, 4)->default(1);
            $table->boolean('is_base')->default(false);
            $table->string('barcode')->nullable();
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('default_price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->string('mutation_type', 40);
            $table->decimal('quantity', 18, 4);
            $table->decimal('quantity_before', 18, 4);
            $table->decimal('quantity_after', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->decimal('avg_cost_after', 18, 4)->default(0);
            $table->timestamp('mutated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
