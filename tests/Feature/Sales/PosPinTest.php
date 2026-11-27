<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Transformers\AuthUserResource;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Sales\Http\Middleware\EnsurePosPinUnlocked;
use Modules\Sales\Services\PosPinService;
use Modules\User\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PosPinTest extends TestCase
{
    private Merchant $merchantA;
    private Merchant $merchantB;
    private User $cashierA;
    private User $cashierB;
    private PosPinService $pinService;

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

        DB::table('users')->insert([
            [
                'id' => 1,
                'merchant_id' => 1,
                'name' => 'Kasir A',
                'username' => 'kasir_a',
                'email' => 'kasir-a@test.id',
                'password' => Hash::make('Password123!'),
                'pos_pin' => null,
                'pos_pin_enabled' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'merchant_id' => 2,
                'name' => 'Kasir B',
                'username' => 'kasir_b',
                'email' => 'kasir-b@test.id',
                'password' => Hash::make('Password456!'),
                'pos_pin' => null,
                'pos_pin_enabled' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->cashierA = User::query()->findOrFail(1);
        $this->cashierB = User::withoutGlobalScopes()->findOrFail(2);
        $this->pinService = new PosPinService();
    }

    protected function tearDown(): void
    {
        MerchantContext::clear();
        parent::tearDown();
    }

    public function test_cashier_can_enable_and_verify_six_digit_pin_and_hash_is_hidden(): void
    {
        $result = $this->pinService->updatePin($this->cashierA, [
            'enabled' => true,
            'pin' => '123456',
        ]);

        $this->assertTrue($result['pos_pin_enabled']);
        $this->assertTrue($result['has_pos_pin']);

        $this->cashierA->refresh();
        $this->assertNotSame('123456', $this->cashierA->pos_pin);
        $this->assertTrue(Hash::check('123456', (string) $this->cashierA->pos_pin));

        // Pastikan pos_pin tidak pernah bocor pada array serialization maupun AuthUserResource
        $this->assertArrayNotHasKey('pos_pin', $this->cashierA->toArray());
        $resource = AuthUserResource::make($this->cashierA)->resolve();
        $this->assertArrayNotHasKey('pos_pin', $resource);
        $this->assertTrue($resource['pos_pin_enabled']);
        $this->assertTrue($resource['has_pos_pin']);

        // Verifikasi PIN benar
        $verified = $this->pinService->verifyPin($this->cashierA, '123456');
        $this->assertTrue($verified['verified']);

        // Verifikasi PIN salah ditolak
        $this->expectException(ValidationException::class);
        $this->pinService->verifyPin($this->cashierA, '654321');
    }

    public function test_enabling_first_time_without_pin_fails(): void
    {
        $this->expectException(ValidationException::class);
        $this->pinService->updatePin($this->cashierA, [
            'enabled' => true,
        ]);
    }

    public function test_changing_or_disabling_active_pin_requires_current_pin_or_password(): void
    {
        $this->pinService->updatePin($this->cashierA, [
            'enabled' => true,
            'pin' => '112233',
        ]);
        $this->cashierA->refresh();

        // Gagal nonaktifkan tanpa kredensial
        try {
            $this->pinService->updatePin($this->cashierA, ['enabled' => false]);
            $this->fail('Seharusnya gagal menonaktifkan PIN tanpa PIN lama atau password.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_pin', $e->errors());
        }

        // Gagal ubah PIN dengan current_pin salah
        try {
            $this->pinService->updatePin($this->cashierA, [
                'enabled' => true,
                'pin' => '445566',
                'current_pin' => '999999',
            ]);
            $this->fail('Seharusnya gagal mengubah PIN dengan PIN lama yang salah.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_pin', $e->errors());
        }

        // Berhasil ubah PIN dengan current_pin benar
        $this->pinService->updatePin($this->cashierA, [
            'enabled' => true,
            'pin' => '445566',
            'current_pin' => '112233',
        ]);
        $this->cashierA->refresh();
        $this->assertTrue($this->pinService->verifyPin($this->cashierA, '445566')['verified']);

        // Berhasil nonaktifkan PIN dengan current_password akun
        $disabled = $this->pinService->updatePin($this->cashierA, [
            'enabled' => false,
            'current_password' => 'Password123!',
        ]);
        $this->assertFalse($disabled['pos_pin_enabled']);
        $this->assertTrue($disabled['has_pos_pin']);
    }

    public function test_unlock_with_password_fallback_and_optional_disable(): void
    {
        $this->pinService->updatePin($this->cashierA, [
            'enabled' => true,
            'pin' => '246810',
        ]);
        $this->cashierA->refresh();

        // Password salah ditolak
        try {
            $this->pinService->unlockWithPassword($this->cashierA, 'WrongPass');
            $this->fail('Seharusnya gagal dengan password salah.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }

        // Unlock dengan password benar tanpa menonaktifkan PIN
        $res1 = $this->pinService->unlockWithPassword($this->cashierA, 'Password123!', false);
        $this->assertTrue($res1['verified']);
        $this->assertTrue($res1['pos_pin_enabled']);
        $this->assertTrue($res1['has_pos_pin']);

        // Unlock dengan password benar + disable_pin = true
        $res2 = $this->pinService->unlockWithPassword($this->cashierA, 'Password123!', true);
        $this->assertTrue($res2['verified']);
        $this->assertFalse($res2['pos_pin_enabled']);
        $this->assertFalse($res2['has_pos_pin']);
    }

    public function test_cross_merchant_isolation_blocks_other_merchant_user(): void
    {
        // MerchantContext saat ini = Merchant A (id 1), tetapi user adalah Kasir B (merchant_id 2)
        $this->expectException(HttpException::class);
        $this->pinService->updatePin($this->cashierB, [
            'enabled' => true,
            'pin' => '123456',
        ]);
    }

    public function test_server_middleware_blocks_pos_requests_until_pin_is_verified_and_after_lock_or_idle(): void
    {
        $middleware = new EnsurePosPinUnlocked($this->pinService);
        $session = new Store('test_session', new ArraySessionHandler(120));
        $session->start();

        $makeRequest = function (User $user) use ($session): Request {
            $request = Request::create('/api/sales/transactions', 'POST');
            $request->setLaravelSession($session);
            $request->setUserResolver(fn (?string $guard = null) => $user);

            return $request;
        };

        // 1. Jika PIN belum aktif, middleware meloloskan request
        $resNoPin = $middleware->handle($makeRequest($this->cashierA), fn () => response()->json(['ok' => true]));
        $this->assertSame(200, $resNoPin->getStatusCode());

        // 2. Aktifkan PIN (tanpa request -> belum unlock di session)
        $this->pinService->updatePin($this->cashierA, [
            'enabled' => true,
            'pin' => '123456',
        ]);
        $this->cashierA->refresh();

        // Request transaksi tanpa verifikasi PIN ditolak server dengan 423 Locked (tidak bisa di-bypass Inspect Element!)
        $resLocked = $middleware->handle($makeRequest($this->cashierA), fn () => response()->json(['ok' => true]));
        $this->assertSame(423, $resLocked->getStatusCode());
        $this->assertSame('POS_PIN_LOCKED', json_decode((string) $resLocked->getContent(), true)['error']['code'] ?? null);

        // 3. Setelah verifikasi PIN di server, request transaksi diloloskan
        $this->pinService->verifyPin($this->cashierA, '123456', $makeRequest($this->cashierA));
        $resUnlocked = $middleware->handle($makeRequest($this->cashierA), fn () => response()->json(['ok' => true]));
        $this->assertSame(200, $resUnlocked->getStatusCode());

        // 4. Setelah idle >= 10 menit di server, otomatis ditolak 423 Locked lagi
        $state = $session->get('pos_pin_lock_state');
        $state['last_activity_at'] = now()->subMinutes(11)->timestamp;
        $session->put('pos_pin_lock_state', $state);

        $resIdle = $middleware->handle($makeRequest($this->cashierA), fn () => response()->json(['ok' => true]));
        $this->assertSame(423, $resIdle->getStatusCode());

        // 5. Buka lagi lalu kunci eksplisit via markSessionLocked -> langsung ditolak 423 Locked
        $this->pinService->verifyPin($this->cashierA, '123456', $makeRequest($this->cashierA));
        $this->pinService->markSessionLocked($makeRequest($this->cashierA), $this->cashierA);
        $resManualLocked = $middleware->handle($makeRequest($this->cashierA), fn () => response()->json(['ok' => true]));
        $this->assertSame(423, $resManualLocked->getStatusCode());
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
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('avatar')->nullable();
            $table->string('password');
            $table->string('pos_pin')->nullable();
            $table->boolean('pos_pin_enabled')->default(false);
            $table->timestamp('pos_pin_updated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
