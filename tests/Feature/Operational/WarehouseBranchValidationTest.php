<?php

namespace Tests\Feature\Operational;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\Region;
use Modules\Operational\Models\Warehouse;
use Modules\Operational\Services\BranchService;
use Modules\Operational\Services\WarehouseService;
use Tests\TestCase;

class WarehouseBranchValidationTest extends TestCase
{
    private Merchant $merchant;
    private Region $regionJateng;
    private Region $regionJatim;
    private Branch $branchSemarang;
    private WarehouseService $warehouseService;
    private BranchService $branchService;

    protected function setUp(): void
    {
        parent::setUp();
        MerchantContext::clear();

        $this->createSchema();

        DB::table('merchants')->insert([
            'id' => 1,
            'name' => 'Merchant Test',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->merchant = Merchant::query()->findOrFail(1);
        MerchantContext::set($this->merchant);

        $this->regionJateng = Region::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Jawa Tengah',
            'code' => 'JTG',
            'type' => 'province',
            'is_active' => true,
        ]);

        $this->regionJatim = Region::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Jawa Timur',
            'code' => 'JTM',
            'type' => 'province',
            'is_active' => true,
        ]);

        $this->warehouseService = app(WarehouseService::class);
        $this->branchService = app(BranchService::class);

        $this->branchSemarang = $this->branchService->create([
            'name' => 'Cabang Semarang',
            'code' => 'SMG',
            'region_id' => $this->regionJateng->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    public function test_can_create_independent_warehouse_without_branch(): void
    {
        $warehouse = $this->warehouseService->create([
            'name' => 'Gudang Pusat Jateng',
            'code' => 'WH-PST-01',
            'region_ids' => [$this->regionJateng->id],
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'name' => 'Gudang Pusat Jateng']);
        $this->assertDatabaseMissing('branch_warehouses', ['warehouse_id' => $warehouse->id]);
    }

    public function test_can_create_warehouse_assigned_to_branch(): void
    {
        $warehouse = $this->warehouseService->create([
            'name' => 'Gudang Cadangan Semarang',
            'code' => 'WH-SMG-02',
            'region_ids' => [$this->regionJateng->id],
            'branch_id' => $this->branchSemarang->id,
            'is_primary' => false,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('branch_warehouses', [
            'branch_id' => $this->branchSemarang->id,
            'warehouse_id' => $warehouse->id,
            'is_primary' => false,
        ]);
    }

    public function test_rejects_creating_warehouse_for_branch_if_region_does_not_match(): void
    {
        $this->expectException(ValidationException::class);

        $this->warehouseService->create([
            'name' => 'Gudang Nyasar',
            'code' => 'WH-NYS-01',
            'region_ids' => [$this->regionJatim->id],
            'branch_id' => $this->branchSemarang->id,
            'is_active' => true,
        ]);
    }

    public function test_cannot_deactivate_primary_warehouse_of_active_branch(): void
    {
        $primaryWarehouse = $this->branchSemarang->mainWarehouse;
        $this->assertNotNull($primaryWarehouse);

        $this->expectException(ValidationException::class);

        $this->warehouseService->update($primaryWarehouse, [
            'name' => $primaryWarehouse->name,
            'code' => $primaryWarehouse->code,
            'region_ids' => [$this->regionJateng->id],
            'is_active' => false,
        ]);
    }

    public function test_rejects_moving_primary_warehouse_in_branch_sync_if_region_mismatch(): void
    {
        $warehouseJatim = $this->warehouseService->create([
            'name' => 'Gudang Surabaya',
            'code' => 'WH-SBY-01',
            'region_ids' => [$this->regionJatim->id],
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        $this->branchService->update($this->branchSemarang, [
            'name' => $this->branchSemarang->name,
            'code' => $this->branchSemarang->code,
            'region_id' => $this->branchSemarang->region_id,
            'is_active' => true,
            'warehouse_ids' => [$this->branchSemarang->primary_warehouse_id, $warehouseJatim->id],
            'primary_warehouse_id' => $warehouseJatim->id,
        ]);
    }

    public function test_cannot_delete_warehouse_with_remaining_stock(): void
    {
        $warehouse = $this->warehouseService->create([
            'name' => 'Gudang Stok Ada',
            'code' => 'WH-STK-01',
            'region_ids' => [$this->regionJateng->id],
            'is_active' => true,
        ]);

        DB::table('warehouse_stocks')->insert([
            'merchant_id' => $this->merchant->id,
            'warehouse_id' => $warehouse->id,
            'product_variant_id' => 999,
            'quantity' => 15,
            'avg_cost' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->warehouseService->delete($warehouse);
    }

    public function test_cannot_delete_warehouse_with_active_transfer(): void
    {
        $warehouse = $this->warehouseService->create([
            'name' => 'Gudang Transfer Aktif',
            'code' => 'WH-TRF-01',
            'region_ids' => [$this->regionJateng->id],
            'is_active' => true,
        ]);

        DB::table('stock_transfers')->insert([
            'merchant_id' => $this->merchant->id,
            'from_warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $this->branchSemarang->primary_warehouse_id,
            'status' => 'shipped',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->warehouseService->delete($warehouse);
    }

    private function createSchema(): void
    {
        foreach (['stock_transfers', 'stock_adjustments', 'warehouse_stocks', 'warehouse_regions', 'branch_warehouses', 'warehouses', 'branches', 'regions', 'merchants'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('type')->default('province');
            $table->unsignedBigInteger('parent_region_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->string('slug');
            $table->string('code');
            $table->string('type')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('primary_warehouse_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('code');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('village_code')->nullable();
            $table->string('district_code')->nullable();
            $table->string('regency_code')->nullable();
            $table->string('province_code')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('logo')->nullable();
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('warehouse_regions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('region_id');
            $table->timestamps();
        });

        Schema::create('branch_warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('avg_cost', 18, 4)->default(0);
            $table->timestamp('last_mutated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('from_warehouse_id');
            $table->unsignedBigInteger('to_warehouse_id');
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('status')->default('draft');
            $table->timestamps();
        });
    }
}
