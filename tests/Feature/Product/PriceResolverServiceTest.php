<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Product\Models\ProductVariantUnit;
use Modules\Product\Services\PriceListService;
use Modules\Product\Services\PriceResolverService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PriceResolverServiceTest extends TestCase
{
    private Merchant $merchant;
    private PriceResolverService $resolver;

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
            'name' => 'Kopi Nusantara',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('branches')->insert([
            ['id' => 10, 'merchant_id' => 1, 'name' => 'Cabang Pusat', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'merchant_id' => 1, 'name' => 'Cabang Mall', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('price_lists')->insert([
            ['id' => 101, 'merchant_id' => 1, 'code' => 'GOFOOD', 'name' => 'GoFood', 'default_markup_percent' => 20.0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'merchant_id' => 1, 'code' => 'SHOPEEFOOD', 'name' => 'ShopeeFood', 'default_markup_percent' => 25.0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 103, 'merchant_id' => 1, 'code' => 'MANUAL', 'name' => 'Manual Only', 'default_markup_percent' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('product_variant_units')->insert([
            ['id' => 501, 'merchant_id' => 1, 'product_variant_id' => 1, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'cost' => 10000, 'default_price' => 20000, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 502, 'merchant_id' => 1, 'product_variant_id' => 2, 'unit_id' => 1, 'conversion_value' => 1, 'is_base' => true, 'cost' => 12000, 'default_price' => 25000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->merchant = Merchant::query()->findOrFail(1);
        $this->resolver = app(PriceResolverService::class);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    public function test_applies_default_markup_percent_and_respects_manual_override_priority(): void
    {
        MerchantContext::set($this->merchant);

        // Cabang Mall (11) punya harga dasar cabang khusus Rp 22.000 untuk unit 501 (tanpa price list)
        DB::table('product_prices')->insert([
            [
                'merchant_id' => 1,
                'product_variant_unit_id' => 501,
                'branch_id' => 11,
                'price_list_id' => null,
                'min_qty' => 1,
                'price' => 22000,
                'pricing_mode' => 'fixed',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Global override GoFood (101) untuk unit 501 = Rp 26.000 (HARUS menang atas harga cabang 11 Rp 22.000!)
            [
                'merchant_id' => 1,
                'product_variant_unit_id' => 501,
                'branch_id' => null,
                'price_list_id' => 101,
                'min_qty' => 1,
                'price' => 26000,
                'pricing_mode' => 'fixed',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $unit501 = ProductVariantUnit::query()->findOrFail(501);
        $unit502 = ProductVariantUnit::query()->findOrFail(502);

        // 1. Unit 501 di Cabang 11 + GoFood (101) -> global override Rp 26.000 (TIDAK ketimpa branch base Rp 22.000)
        $r1 = $this->resolver->resolveForUnit($unit501, 1, 101, 11);
        $this->assertSame(26000.0, $r1['price']);
        $this->assertSame('product_price', $r1['source']);

        // 2. Unit 501 di Cabang 11 + ShopeeFood (102, +25% otomatis) -> tidak ada override ShopeeFood,
        //    maka markup +25% diterapkan ke base price cabang 11 (22.000 * 1.25 = 27.500)
        $r2 = $this->resolver->resolveForUnit($unit501, 1, 102, 11);
        $this->assertSame(27500.0, $r2['price']);
        $this->assertSame('price_list_markup', $r2['source']);

        // 3. Unit 502 di Cabang 10 + GoFood (101, +20% otomatis) -> 25.000 * 1.20 = 30.000
        $r3 = $this->resolver->resolveForUnit($unit502, 1, 101, 10);
        $this->assertSame(30000.0, $r3['price']);
        $this->assertSame('price_list_markup', $r3['source']);

        // 4. Unit 502 di Cabang 10 + Manual Only (103, markup null) -> fallback ke default_price 25.000
        $r4 = $this->resolver->resolveForUnit($unit502, 1, 103, 10);
        $this->assertSame(25000.0, $r4['price']);
        $this->assertSame('default_price', $r4['source']);

        // 5. Batch resolveMany() harus konsisten dengan resolveForUnit()
        $many = $this->resolver->resolveMany([$unit501, $unit502], 1.0, 101, 11);
        $this->assertSame(26000.0, $many[501]['price']);
        $this->assertSame(30000.0, $many[502]['price']);

        // 6. Batch resolveManyForLines() harus konsisten dengan resolveForUnit()
        $lines = $this->resolver->resolveManyForLines([
            ['unit' => $unit501, 'quantity' => 1.0],
            ['unit' => $unit502, 'quantity' => 2.0],
        ], 102, 11);
        $this->assertSame(27500.0, $lines[0]['price']);
        $this->assertSame(31250.0, $lines[1]['price']); // 25.000 * 1.25
    }

    public function test_applies_auto_rounding_modes_for_markup_price_lists(): void
    {
        MerchantContext::set($this->merchant);

        // Unit baru dengan harga dasar Rp 23.000 (+15% = Rp 26.450)
        DB::table('product_variant_units')->insert([
            'id' => 503,
            'merchant_id' => 1,
            'product_variant_id' => 3,
            'unit_id' => 1,
            'conversion_value' => 1,
            'is_base' => true,
            'cost' => 10000,
            'default_price' => 23000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('price_lists')->insert([
            ['id' => 201, 'merchant_id' => 1, 'code' => 'R500_UP', 'name' => 'Bulat 500 Up', 'default_markup_percent' => 15.0, 'rounding_unit' => 500, 'rounding_mode' => 'up', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 202, 'merchant_id' => 1, 'code' => 'R500_DOWN', 'name' => 'Bulat 500 Down', 'default_markup_percent' => 15.0, 'rounding_unit' => 500, 'rounding_mode' => 'down', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 203, 'merchant_id' => 1, 'code' => 'R1000_NEAR', 'name' => 'Bulat 1000 Near', 'default_markup_percent' => 15.0, 'rounding_unit' => 1000, 'rounding_mode' => 'nearest', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 204, 'merchant_id' => 1, 'code' => 'R500_EXACT', 'name' => 'Exact Multiple', 'default_markup_percent' => 20.0, 'rounding_unit' => 500, 'rounding_mode' => 'up', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $unit503 = ProductVariantUnit::query()->findOrFail(503);
        $unit502 = ProductVariantUnit::query()->findOrFail(502); // default_price 25.000 -> +20% = 30.000 (exact multiple of 500)

        // 26.450 -> up to 500 = 26.500
        $this->assertSame(26500.0, $this->resolver->resolveForUnit($unit503, 1, 201, 10)['price']);
        // 26.450 -> down to 500 = 26.000
        $this->assertSame(26000.0, $this->resolver->resolveForUnit($unit503, 1, 202, 10)['price']);
        // 26.450 -> nearest 1000 = 26.000
        $this->assertSame(26000.0, $this->resolver->resolveForUnit($unit503, 1, 203, 10)['price']);
        // 25.000 * 1.20 = 30.000 (exact multiple of 500, must stay 30.000 not 30.500!)
        $this->assertSame(30000.0, $this->resolver->resolveForUnit($unit502, 1, 204, 10)['price']);
    }

    public function test_bulk_price_list_items_matrix_upsert_clear_and_tenant_isolation(): void
    {
        MerchantContext::set($this->merchant);

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_id');
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
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

        DB::table('units')->insert(['id' => 1, 'merchant_id' => 1, 'name' => 'Cup', 'code' => 'CUP']);
        DB::table('products')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'Kopi Susu', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'Matcha Latte', 'is_active' => true],
        ]);
        DB::table('product_variants')->insert([
            ['id' => 1, 'merchant_id' => 1, 'product_id' => 1, 'sku' => 'KS-01', 'name' => 'Regular', 'is_active' => true],
            ['id' => 2, 'merchant_id' => 1, 'product_id' => 2, 'sku' => 'ML-01', 'name' => 'Large', 'is_active' => true],
        ]);
        // Foreign merchant unit (merchant_id = 2)
        DB::table('product_variant_units')->insert([
            'id' => 999,
            'merchant_id' => 2,
            'product_variant_id' => 99,
            'unit_id' => 1,
            'conversion_value' => 1,
            'is_base' => true,
            'cost' => 5000,
            'default_price' => 15000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var PriceListService $plService */
        $plService = app(PriceListService::class);

        // 1. Bulk upsert override for unit 501 (Rp 26.500) on GoFood (101, +20%)
        $res1 = $plService->bulkUpsertItems(101, [
            ['product_variant_unit_id' => 501, 'override_price' => 26500],
        ]);
        $this->assertSame(1, $res1['updated']);
        $this->assertSame(0, $res1['cleared']);

        // 2. List matrix: 501 shows override 26.500, 502 shows auto 30.000
        $matrix = $plService->listItems(101);
        $byUnit = collect($matrix['data'])->keyBy('product_variant_unit_id');
        $this->assertTrue($byUnit[501]['is_overridden']);
        $this->assertSame(26500.0, $byUnit[501]['effective_price']);
        $this->assertFalse($byUnit[502]['is_overridden']);
        $this->assertSame(30000.0, $byUnit[502]['effective_price']);

        // 3. Clear override for unit 501 -> returns to auto price 24.000 (20.000 * 1.20)
        $res2 = $plService->bulkUpsertItems(101, [
            ['product_variant_unit_id' => 501, 'override_price' => null],
        ]);
        $this->assertSame(0, $res2['updated']);
        $this->assertSame(1, $res2['cleared']);

        $matrixAfter = $plService->listItems(101, ['only_overridden' => true]);
        $this->assertCount(0, $matrixAfter['data']);

        // 4. Cross-merchant unit MUST be rejected
        $this->expectException(ValidationException::class);
        $plService->bulkUpsertItems(101, [
            ['product_variant_unit_id' => 999, 'override_price' => 10000],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('code', 50)->nullable();
            $table->string('name', 120);
            $table->decimal('default_markup_percent', 6, 2)->nullable();
            $table->unsignedInteger('rounding_unit')->nullable();
            $table->string('rounding_mode', 10)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_variant_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedBigInteger('unit_id');
            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->boolean('is_base')->default(false);
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('default_price', 15, 2)->default(0);
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
            $table->decimal('price', 15, 2);
            $table->string('pricing_mode', 20)->default('fixed');
            $table->string('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
