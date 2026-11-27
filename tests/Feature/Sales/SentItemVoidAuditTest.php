<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Sales\Services\PosPinService;
use Modules\User\Models\User;
use Tests\TestCase;

class SentItemVoidAuditTest extends TestCase
{
    private Merchant $merchant;
    private PosPinService $pinService;

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
            ['id' => 1, 'name' => 'Kopi Nusantara', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Merchant Lain', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('roles')->insert([
            ['id' => 1, 'merchant_id' => 1, 'name' => 'owner', 'guard_name' => 'merchant'],
            ['id' => 2, 'merchant_id' => 1, 'name' => 'cashier', 'guard_name' => 'merchant'],
            ['id' => 3, 'merchant_id' => 2, 'name' => 'owner', 'guard_name' => 'merchant'],
        ]);

        DB::table('users')->insert([
            [
                'id' => 10,
                'merchant_id' => 1,
                'name' => 'Owner Cafe',
                'email' => 'owner@kopi.test',
                'password' => Hash::make('secret123'),
                'pos_pin' => Hash::make('654321'),
                'pos_pin_enabled' => true,
            ],
            [
                'id' => 11,
                'merchant_id' => 1,
                'name' => 'Kasir Biasa',
                'email' => 'kasir@kopi.test',
                'password' => Hash::make('secret123'),
                'pos_pin' => Hash::make('111111'),
                'pos_pin_enabled' => true,
            ],
            [
                'id' => 20,
                'merchant_id' => 2,
                'name' => 'Owner Merchant Lain',
                'email' => 'owner@lain.test',
                'password' => Hash::make('secret123'),
                'pos_pin' => Hash::make('999999'),
                'pos_pin_enabled' => true,
            ],
        ]);

        DB::table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => 10, 'merchant_id' => 1],
            ['role_id' => 2, 'model_type' => User::class, 'model_id' => 11, 'merchant_id' => 1],
            ['role_id' => 3, 'model_type' => User::class, 'model_id' => 20, 'merchant_id' => 2],
        ]);

        $this->merchant = Merchant::query()->findOrFail(1);
        $this->pinService = app(PosPinService::class);
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        Cache::flush();
        parent::tearDown();
    }

    public function test_authorizes_supervisor_pin_and_rejects_cashier_or_foreign_merchant_pin(): void
    {
        MerchantContext::set($this->merchant);
        setPermissionsTeamId(1);

        $cashier = User::query()->findOrFail(11);

        // 1. Valid Owner PIN (654321) succeeds and returns Owner user
        $approver = $this->pinService->authorizeSupervisorAction(1, '654321', $cashier);
        $this->assertNotNull($approver);
        $this->assertSame(10, (int) $approver->id);

        // 2. Cashier's own PIN (111111) MUST be rejected
        try {
            $this->pinService->authorizeSupervisorAction(1, '111111', $cashier);
            $this->fail('Expected ValidationException for cashier PIN.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supervisor_pin', $e->errors());
        }

        // 3. Merchant 2 Owner PIN (999999) MUST be rejected (strict tenant isolation)
        try {
            $this->pinService->authorizeSupervisorAction(1, '999999', $cashier);
            $this->fail('Expected ValidationException for cross-merchant supervisor PIN.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supervisor_pin', $e->errors());
        }

        // 4. Empty PIN when a supervisor PIN exists MUST be rejected
        $this->expectException(ValidationException::class);
        $this->pinService->authorizeSupervisorAction(1, null, $cashier);
    }

    private function createSchema(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('pos_pin')->nullable();
            $table->boolean('pos_pin_enabled')->default(false);
            $table->timestamp('pos_pin_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id')->nullable();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('merchant_id');
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('merchant_id');
        });
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
        });
    }
}
