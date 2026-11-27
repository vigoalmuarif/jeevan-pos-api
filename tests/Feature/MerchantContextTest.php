<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MerchantContextTest extends TestCase
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
            $table->enum('status', ['active', 'inactive', 'suspended', 'trial'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
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
        Route::middleware(['resolve.merchant'])->group(function () {
            Route::get('/testing/merchants/{merchant}', function (Request $request) {
                return response()->json([
                    'success' => true,
                    'merchant_id' => MerchantContext::id(),
                    'merchant_slug' => MerchantContext::slug(),
                    'merchant_ulid' => MerchantContext::ulid(),
                    'spatie_team_id' => getPermissionsTeamId(),
                ]);
            });

            Route::get('/testing/merchant-user-scoped', function (Request $request) {
                return response()->json([
                    'success' => true,
                    'merchant_id' => MerchantContext::id(),
                    'spatie_team_id' => getPermissionsTeamId(),
                ]);
            });
        });
    }

    private function createMerchant(array $attributes = []): Merchant
    {
        static $counter = 1;
        $counter++;

        return Merchant::create(array_merge([
            'name' => 'Toko Barokah ' . $counter,
            'slug' => 'toko-barokah-' . $counter,
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'email' => "toko{$counter}@example.com",
            'status' => 'active',
        ], $attributes));
    }

    private function createUser(Merchant $merchant, array $attributes = []): User
    {
        return User::create(array_merge([
            'merchant_id' => $merchant->id,
            'name' => 'Kasir 1',
            'username' => 'kasir1',
            'email' => 'kasir1@example.com',
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ], $attributes));
    }

    public function test_resolves_merchant_by_id(): void
    {
        $merchant = $this->createMerchant(['name' => 'Toko Sukses']);

        $response = $this->getJson("/testing/merchants/{$merchant->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'merchant_id' => $merchant->id,
                'spatie_team_id' => $merchant->id,
            ]);
    }

    public function test_resolves_merchant_by_slug(): void
    {
        $merchant = $this->createMerchant(['slug' => 'kedai-kopi-sedap']);

        $response = $this->getJson('/testing/merchants/kedai-kopi-sedap');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'merchant_id' => $merchant->id,
                'merchant_slug' => 'kedai-kopi-sedap',
                'spatie_team_id' => $merchant->id,
            ]);
    }

    public function test_resolves_merchant_by_ulid(): void
    {
        $merchant = $this->createMerchant(['ulid' => '01HZY777777777777777777777']);

        $response = $this->getJson('/testing/merchants/01HZY777777777777777777777');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'merchant_id' => $merchant->id,
                'merchant_ulid' => '01HZY777777777777777777777',
            ]);
    }

    public function test_returns_404_when_merchant_not_found(): void
    {
        $response = $this->getJson('/testing/merchants/non-existent-merchant');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                ],
            ]);
    }

    public function test_prevents_cross_tenant_access_for_authenticated_merchant_user(): void
    {
        $merchantA = $this->createMerchant(['name' => 'Merchant A', 'slug' => 'merchant-a', 'ulid' => '01HZY00000000000000000000A']);
        $merchantB = $this->createMerchant(['name' => 'Merchant B', 'slug' => 'merchant-b', 'ulid' => '01HZY00000000000000000000B']);

        $userA = $this->createUser($merchantA, ['username' => 'usera', 'email' => 'usera@example.com']);

        $response = $this->actingAs($userA, 'merchant')
            ->getJson("/testing/merchants/{$merchantB->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED_MERCHANT_ACCESS',
                ],
            ]);
    }

    public function test_allows_access_when_authenticated_user_belongs_to_merchant(): void
    {
        $merchantA = $this->createMerchant(['name' => 'Merchant A', 'slug' => 'merchant-a']);
        $userA = $this->createUser($merchantA, ['username' => 'usera']);

        $response = $this->actingAs($userA, 'merchant')
            ->getJson("/testing/merchants/{$merchantA->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'merchant_id' => $merchantA->id,
            ]);
    }

    public function test_rejects_inactive_or_suspended_merchant(): void
    {
        $inactive = $this->createMerchant(['name' => 'Inactive Store', 'slug' => 'inactive-store', 'status' => 'inactive']);
        $suspended = $this->createMerchant(['name' => 'Suspended Store', 'slug' => 'suspended-store', 'status' => 'suspended']);

        $res1 = $this->getJson("/testing/merchants/{$inactive->id}");
        $res1->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'MERCHANT_INACTIVE',
                ],
            ]);

        $res2 = $this->getJson("/testing/merchants/{$suspended->id}");
        $res2->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'MERCHANT_INACTIVE',
                ],
            ]);
    }

    public function test_allows_trial_status_merchant(): void
    {
        $trialMerchant = $this->createMerchant(['name' => 'Trial Store', 'slug' => 'trial-store', 'status' => 'trial']);

        $response = $this->getJson("/testing/merchants/{$trialMerchant->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'merchant_id' => $trialMerchant->id,
            ]);
    }

    public function test_fallback_resolves_merchant_from_authenticated_user_without_route_param(): void
    {
        $merchant = $this->createMerchant();
        $user = $this->createUser($merchant);

        $response = $this->actingAs($user, 'merchant')
            ->getJson('/testing/merchant-user-scoped');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'merchant_id' => $merchant->id,
                'spatie_team_id' => $merchant->id,
            ]);
    }

    public function test_rejects_user_without_merchant_id_on_user_scoped_route(): void
    {
        $userWithoutMerchant = User::create([
            'merchant_id' => null,
            'name' => 'Unattached User',
            'username' => 'unattached',
            'email' => 'unattached@example.com',
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($userWithoutMerchant, 'merchant')
            ->getJson('/testing/merchant-user-scoped');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED_MERCHANT_ACCESS',
                ],
            ]);
    }

    public function test_merchant_context_is_cleared_after_request_termination(): void
    {
        $merchant = $this->createMerchant();

        $response = $this->getJson("/testing/merchants/{$merchant->id}");
        $response->assertStatus(200);

        // When application terminates (or middleware terminate called), context must be cleared
        $this->assertFalse(MerchantContext::check());
        $this->assertNull(MerchantContext::get());
        $this->assertNull(MerchantContext::id());
    }
}
