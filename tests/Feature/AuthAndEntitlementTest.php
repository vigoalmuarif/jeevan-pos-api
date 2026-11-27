<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantModule;
use Modules\Merchant\Models\Module;
use Modules\Merchant\Models\Plan;
use Modules\Merchant\Models\Subscription;
use Modules\Merchant\Services\ModuleEntitlementService;
use Modules\User\Models\User;
use Tests\TestCase;

class AuthAndEntitlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MerchantContext::clear();

        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            $this->markTestSkipped('These tests require the isolated in-memory SQLite database from phpunit.xml.');
        }

        $this->createSchema();
        $this->registerTestRoutes();
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    private function createSchema(): void
    {
        foreach ([
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'permissions',
            'roles',
            'user_branches',
            'branches',
            'merchant_modules',
            'plan_modules',
            'subscriptions',
            'plans',
            'modules',
            'users',
            'merchants',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('ulid')->nullable()->unique();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('currency')->default('IDR');
            $table->string('timezone')->default('Asia/Jakarta');
            $table->enum('status', ['active', 'inactive', 'suspended', 'trial'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->string('name');
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_all_branches')->default(false);
            $table->unsignedBigInteger('current_active_branch')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('category', ['core', 'business', 'addon'])->default('business');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('module_id');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('plan_id');
            $table->enum('status', ['active', 'trial', 'past_due', 'cancelled'])->default('active');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();
        });

        Schema::create('merchant_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('module_id');
            $table->boolean('is_active')->default(true);
            $table->string('source')->default('plan');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->string('name');
            $table->string('guard_name')->default('merchant');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('merchant');
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->default(1);
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('merchant_id')->default(1);
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
    }

    private function registerTestRoutes(): void
    {
        Route::middleware([
            'resolve.merchant',
            'merchant.active',
            'auth:merchant',
            'module:inventory',
        ])->get('/testing/guarded-inventory/{merchant}', function () {
            return response()->json([
                'success' => true,
                'message' => 'Inventory module accessible.',
            ]);
        });
    }

    public function test_module_entitlement_precedence_and_caching(): void
    {
        $service = app(ModuleEntitlementService::class);

        // 1. Create merchant
        $merchant = Merchant::create([
            'name' => 'Toko Kelontong',
            'slug' => 'toko-kelontong',
            'ulid' => (string) Str::ulid(),
            'status' => 'active',
        ]);

        // 2. Create modules:
        // - mod_inv: active globally
        // - mod_pos: active globally
        // - mod_dis: INACTIVE globally
        $modInv = Module::create(['code' => 'inventory', 'name' => 'Inventory', 'is_active' => true]);
        $modPos = Module::create(['code' => 'pos', 'name' => 'POS', 'is_active' => true]);
        $modDis = Module::create(['code' => 'distributor', 'name' => 'Distributor', 'is_active' => false]);

        // 3. Create plan with mod_inv and mod_dis
        $plan = Plan::create(['code' => 'basic', 'name' => 'Basic Plan', 'is_active' => true]);
        DB::table('plan_modules')->insert([
            ['plan_id' => $plan->id, 'module_id' => $modInv->id],
            ['plan_id' => $plan->id, 'module_id' => $modDis->id],
        ]);

        // 4. Create active subscription
        $sub = Subscription::create([
            'merchant_id' => $merchant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);

        // Before override:
        // - inventory: entitled (from plan)
        // - pos: not entitled (not in plan)
        // - distributor: NOT entitled (globally inactive even though in plan!)
        $this->assertTrue($service->isEntitled($merchant, 'inventory'));
        $this->assertFalse($service->isEntitled($merchant, 'pos'));
        $this->assertFalse($service->isEntitled($merchant, 'distributor'));

        // Cache must now exist
        $this->assertTrue(Cache::has("merchant:{$merchant->id}:entitlements"));

        // 5. Test Step 2 override:
        // Grant pos via merchant_modules (add-on)
        $addon = MerchantModule::create([
            'merchant_id' => $merchant->id,
            'module_id' => $modPos->id,
            'is_active' => true,
            'source' => 'addon',
        ]);

        // Creating merchant_module should invalidate cache automatically
        $this->assertFalse(Cache::has("merchant:{$merchant->id}:entitlements"));

        // Now pos must be entitled!
        $this->assertTrue($service->isEntitled($merchant, 'pos'));

        // Disable inventory via merchant_modules override
        MerchantModule::create([
            'merchant_id' => $merchant->id,
            'module_id' => $modInv->id,
            'is_active' => false,
            'source' => 'manual',
        ]);

        // Now inventory must NOT be entitled despite being in plan!
        $this->assertFalse($service->isEntitled($merchant, 'inventory'));
    }

    public function test_ensure_module_entitled_middleware_blocks_unentitled_request(): void
    {
        $merchant = Merchant::create([
            'name' => 'Toko Sepatu',
            'slug' => 'toko-sepatu',
            'ulid' => (string) Str::ulid(),
            'status' => 'active',
        ]);

        $user = User::create([
            'merchant_id' => $merchant->id,
            'name' => 'Admin Sepatu',
            'username' => 'admin_sepatu',
            'email' => 'admin@sepatu.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        // Without inventory module entitled, accessing guarded route should return 403
        $response = $this->actingAs($user, 'merchant')
            ->getJson("/testing/guarded-inventory/{$merchant->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'MODULE_NOT_ENTITLED',
                ],
            ]);
    }

    public function test_context_endpoint_returns_merchant_user_and_entitled_modules(): void
    {
        $merchant = Merchant::create([
            'name' => 'Toko Roti Wangi',
            'slug' => 'toko-roti-wangi',
            'ulid' => (string) Str::ulid(),
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
        ]);

        $user = User::create([
            'merchant_id' => $merchant->id,
            'name' => 'Owner Roti',
            'username' => 'owner_roti',
            'email' => 'owner@roti.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
            'is_owner' => true,
        ]);

        $mod = Module::create(['code' => 'product', 'name' => 'Product', 'is_active' => true]);
        MerchantModule::create([
            'merchant_id' => $merchant->id,
            'module_id' => $mod->id,
            'is_active' => true,
            'source' => 'plan',
        ]);

        $response = $this->actingAs($user, 'merchant')
            ->getJson("/api/v1/merchants/{$merchant->id}/context");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'merchant' => [
                        'id' => $merchant->id,
                        'slug' => 'toko-roti-wangi',
                        'status' => 'active',
                    ],
                    'user' => [
                        'id' => $user->id,
                        'username' => 'owner_roti',
                    ],
                    'entitled_modules' => ['product'],
                ],
            ]);
    }

    public function test_auth_login_with_valid_and_invalid_credentials(): void
    {
        $merchant = Merchant::create([
            'name' => 'Toko Mainan',
            'slug' => 'toko-mainan',
            'ulid' => (string) Str::ulid(),
            'status' => 'active',
        ]);

        \Modules\Operational\Models\Branch::create([
            'merchant_id' => $merchant->id,
            'name' => 'Cabang Pusat',
            'is_main' => true,
            'is_active' => true,
        ]);

        $mod = Module::create(['code' => 'core_auth', 'name' => 'Auth Core', 'is_active' => true]);
        MerchantModule::create([
            'merchant_id' => $merchant->id,
            'module_id' => $mod->id,
            'is_active' => true,
        ]);

        $user = User::create([
            'merchant_id' => $merchant->id,
            'name' => 'Budi Staff',
            'username' => 'budistaff',
            'email' => 'budi@mainan.com',
            'password' => Hash::make('secret_password'),
            'is_active' => true,
            'is_owner' => true,
            'is_all_branches' => true,
        ]);

        $role = \Modules\Permission\Models\Role::create([
            'merchant_id' => $merchant->id,
            'name' => 'owner',
            'guard_name' => 'merchant',
        ]);
        DB::table('model_has_roles')->insert([
            'merchant_id' => $merchant->id,
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);

        // Invalid password -> AuthenticationException handled by Laravel
        $resFailed = $this->postJson('/api/v1/auth/login', [
            'username' => 'budistaff',
            'password' => 'wrong_password',
        ]);
        $resFailed->assertStatus(401);

        // Valid password -> login successful
        $resSuccess = $this->postJson('/api/v1/auth/login', [
            'username' => 'budistaff',
            'password' => 'secret_password',
        ]);
        $resSuccess->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login successful.',
            ]);
    }

    public function test_auth_me_and_logout_endpoints(): void
    {
        $merchant = Merchant::create([
            'name' => 'Toko Buku',
            'slug' => 'toko-buku',
            'ulid' => (string) Str::ulid(),
            'status' => 'active',
        ]);

        \Modules\Operational\Models\Branch::create([
            'merchant_id' => $merchant->id,
            'name' => 'Cabang Utama',
            'is_main' => true,
            'is_active' => true,
        ]);

        $mod = Module::create(['code' => 'buku_mod', 'name' => 'Buku Mod', 'is_active' => true]);
        MerchantModule::create([
            'merchant_id' => $merchant->id,
            'module_id' => $mod->id,
            'is_active' => true,
        ]);

        $user = User::create([
            'merchant_id' => $merchant->id,
            'name' => 'Siti Kasir',
            'username' => 'sitikasir',
            'email' => 'siti@buku.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
            'is_owner' => true,
            'is_all_branches' => true,
        ]);

        $role2 = \Modules\Permission\Models\Role::create([
            'merchant_id' => $merchant->id,
            'name' => 'cashier',
            'guard_name' => 'merchant',
        ]);
        DB::table('model_has_roles')->insert([
            'merchant_id' => $merchant->id,
            'role_id' => $role2->id,
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);

        // Guest accessing /api/v1/auth/me -> 401
        $resGuest = $this->getJson('/api/v1/auth/me');
        $resGuest->assertStatus(401);

        // Authenticated user accessing /api/v1/auth/me -> 200
        $resAuth = $this->actingAs($user, 'merchant')
            ->getJson('/api/v1/auth/me');
        $resAuth->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Logout
        $resLogout = $this->actingAs($user, 'merchant')
            ->postJson('/api/v1/auth/logout');
        $resLogout->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logged out successfully.',
            ]);
    }
}
