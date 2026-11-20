<?php

namespace Tests\Feature\Operational;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\Region;
use Modules\Operational\Models\Warehouse;
use Modules\Operational\Services\BranchDetailService;
use Modules\Operational\Services\BranchService;
use Modules\Operational\Services\WarehouseService;
use Modules\User\Models\User;
use Tests\TestCase;

class BranchDetailServiceTest extends TestCase
{
    private Merchant $merchant;
    private Region $region;
    private Branch $branch;
    private BranchDetailService $detailService;

    protected function setUp(): void
    {
        parent::setUp();
        MerchantContext::clear();

        $this->createSchema();

        DB::table('merchants')->insert([
            'id' => 1,
            'name' => 'Merchant Test Detail',
            'status' => 'active',
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->merchant = Merchant::query()->findOrFail(1);
        MerchantContext::set($this->merchant);

        $this->region = Region::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'DKI Jakarta',
            'code' => 'DKI',
            'type' => 'province',
            'is_active' => true,
        ]);

        $branchService = app(BranchService::class);
        $this->branch = $branchService->create([
            'name' => 'Cabang Sudirman',
            'code' => 'SDR',
            'region_id' => $this->region->id,
            'is_active' => true,
            'is_main' => true,
            'phone' => '081299998888',
            'email' => 'sudirman@test.com',
            'address' => 'Jl. Jend. Sudirman No. 1',
            'receipt_header' => 'Header Test',
            'receipt_footer' => 'Footer Test',
        ]);

        $this->detailService = app(BranchDetailService::class);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    public function test_get_operational_returns_complete_branch_profile(): void
    {
        $operational = $this->detailService->getOperational($this->branch);

        $this->assertEquals($this->branch->id, $operational['id']);
        $this->assertEquals('Cabang Sudirman', $operational['name']);
        $this->assertEquals('SDR', $operational['code']);
        $this->assertTrue($operational['is_main']);
        $this->assertTrue($operational['is_active']);
        $this->assertEquals('081299998888', $operational['phone']);
        $this->assertEquals('sudirman@test.com', $operational['email']);
        $this->assertEquals('Header Test', $operational['receipt_header']);
        $this->assertEquals('Footer Test', $operational['receipt_footer']);
        $this->assertEquals('DKI Jakarta', $operational['region']['name']);
        $this->assertNotNull($operational['main_warehouse']);
    }

    public function test_get_resources_returns_warehouses_and_users(): void
    {
        // Hubungkan user ke branch
        DB::table('users')->insert([
            'id' => 10,
            'merchant_id' => $this->merchant->id,
            'name' => 'Budi Kasir',
            'username' => 'budikasir',
            'email' => 'budi@test.com',
            'phone' => '0811111111',
            'password' => 'secret',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_branches')->insert([
            'merchant_id' => $this->merchant->id,
            'branch_id' => $this->branch->id,
            'user_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resources = $this->detailService->getResources($this->branch);

        $this->assertArrayHasKey('users', $resources);
        $this->assertArrayHasKey('warehouses', $resources);

        // Validasi Gudang
        $this->assertNotEmpty($resources['warehouses']);
        $wh = $resources['warehouses'][0];
        $this->assertTrue($wh['is_primary']);
        $this->assertTrue($wh['is_active']);

        // Validasi User
        $this->assertCount(1, $resources['users']);
        $this->assertEquals('Budi Kasir', $resources['users'][0]['name']);
        $this->assertEquals('budikasir', $resources['users'][0]['username']);
        $this->assertTrue($resources['users'][0]['is_active']);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('user_branches');
        Schema::dropIfExists('users');
        Schema::dropIfExists('warehouse_regions');
        Schema::dropIfExists('branch_warehouses');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('merchants');

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->foreignId('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->foreignId('merchant_id')->nullable();
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

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
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('type')->default('province');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
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
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_main')->default(false);
            $table->foreignId('region_id')->constrained('regions');
            $table->foreignId('primary_warehouse_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('code');
            $table->enum('type', ['storage', 'distribution_center'])->default('storage');
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branch_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('warehouse_regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->cascadeOnDelete();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }
}
