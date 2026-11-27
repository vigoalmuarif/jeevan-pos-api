<?php

namespace Tests\Feature;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Traits\MerchantAwareJob;
use Modules\Merchant\Models\Merchant;
use Modules\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
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
            Route::get('/isolation/merchants/{merchant}', function (Request $request) {
                return response()->json([
                    'success' => true,
                    'merchant_id' => MerchantContext::id(),
                    'spatie_team_id' => getPermissionsTeamId(),
                ]);
            });
        });

        Route::get('/isolation/public-endpoint', function (Request $request) {
            return response()->json([
                'success' => true,
                'has_merchant_context' => MerchantContext::check(),
                'spatie_team_id' => getPermissionsTeamId(),
            ]);
        });
    }

    private function createMerchant(array $attributes = []): Merchant
    {
        static $counter = 100;
        $counter++;

        return Merchant::create(array_merge([
            'name' => 'Merchant ' . $counter,
            'slug' => 'merchant-' . $counter,
            'ulid' => (string) Str::ulid(),
            'email' => "merchant{$counter}@example.com",
            'status' => 'active',
        ], $attributes));
    }

    private function createUser(Merchant $merchant, array $attributes = []): User
    {
        static $userCounter = 100;
        $userCounter++;

        return User::create(array_merge([
            'merchant_id' => $merchant->id,
            'name' => 'User ' . $userCounter,
            'username' => 'user' . $userCounter,
            'email' => "user{$userCounter}@example.com",
            'password' => bcrypt('secret123'),
            'is_active' => true,
        ], $attributes));
    }

    public function test_request_without_valid_merchant_parameter_returns_404(): void
    {
        $response = $this->getJson('/isolation/merchants/unknown-merchant-id');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                ],
            ]);
    }

    public function test_request_to_inactive_merchant_returns_403(): void
    {
        $inactiveMerchant = $this->createMerchant(['status' => 'inactive']);

        $response = $this->getJson("/isolation/merchants/{$inactiveMerchant->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'MERCHANT_INACTIVE',
                ],
            ]);
    }

    public function test_user_merchant_a_cannot_access_merchant_b_context(): void
    {
        $merchantA = $this->createMerchant(['name' => 'Alpha Corp']);
        $merchantB = $this->createMerchant(['name' => 'Beta Corp']);

        $userA = $this->createUser($merchantA);

        $response = $this->actingAs($userA, 'merchant')
            ->getJson("/isolation/merchants/{$merchantB->id}");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED_MERCHANT_ACCESS',
                ],
            ]);
    }

    public function test_consecutive_requests_do_not_leak_context_or_spatie_team(): void
    {
        $merchantA = $this->createMerchant(['name' => 'Alpha Corp']);
        $merchantB = $this->createMerchant(['name' => 'Beta Corp']);

        // Request 1: to Merchant A
        $res1 = $this->getJson("/isolation/merchants/{$merchantA->id}");
        $res1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'merchant_id' => $merchantA->id,
                'spatie_team_id' => $merchantA->id,
            ]);

        // Request 2: to Merchant B
        $res2 = $this->getJson("/isolation/merchants/{$merchantB->id}");
        $res2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'merchant_id' => $merchantB->id,
                'spatie_team_id' => $merchantB->id,
            ]);

        // Request 3: to a non-merchant public endpoint
        $res3 = $this->getJson('/isolation/public-endpoint');
        $res3->assertStatus(200)
            ->assertJson([
                'success' => true,
                'has_merchant_context' => false,
                'spatie_team_id' => null,
            ]);

        // After all requests terminate, MerchantContext is clean
        $this->assertFalse(MerchantContext::check());
        $this->assertNull(MerchantContext::id());
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_merchant_aware_job_initializes_context_and_cleans_up(): void
    {
        $merchant = $this->createMerchant(['name' => 'Job Merchant']);

        SampleIsolationJob::$lastRecordedContextId = null;
        SampleIsolationJob::$lastRecordedSpatieTeamId = null;

        $job = new SampleIsolationJob($merchant->id);
        dispatch($job);

        // Assert that during job handle(), context and Spatie team were set correctly
        $this->assertSame($merchant->id, SampleIsolationJob::$lastRecordedContextId);
        $this->assertSame($merchant->id, SampleIsolationJob::$lastRecordedSpatieTeamId);

        // Assert that after job finishes, context and Spatie team were reset
        $this->assertFalse(MerchantContext::check());
        $this->assertNull(MerchantContext::id());
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_merchant_aware_job_cleans_up_even_if_exception_occurs(): void
    {
        $merchant = $this->createMerchant(['name' => 'Failing Job Merchant']);

        $job = new SampleIsolationJob($merchant->id, true);

        try {
            dispatch($job);
            $this->fail('Job should have thrown an exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Deliberate job failure.', $e->getMessage());
        }

        // Context must be clean even after exception
        $this->assertFalse(MerchantContext::check());
        $this->assertNull(MerchantContext::id());
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_merchant_aware_job_rejects_inactive_merchant(): void
    {
        $inactiveMerchant = $this->createMerchant(['status' => 'inactive']);

        $job = new SampleIsolationJob($inactiveMerchant->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Merchant [ID: {$inactiveMerchant->id}] is inactive or suspended.");

        dispatch($job);
    }
}

class SampleIsolationJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, MerchantAwareJob;

    public static ?int $lastRecordedContextId = null;
    public static ?int $lastRecordedSpatieTeamId = null;
    public bool $shouldThrow = false;

    public function __construct(int $merchantId, bool $shouldThrow = false)
    {
        $this->initializeMerchantAwareJob($merchantId);
        $this->shouldThrow = $shouldThrow;
    }

    public function handle(): void
    {
        static::$lastRecordedContextId = MerchantContext::id();
        static::$lastRecordedSpatieTeamId = getPermissionsTeamId();

        if ($this->shouldThrow) {
            throw new \RuntimeException('Deliberate job failure.');
        }
    }
}
