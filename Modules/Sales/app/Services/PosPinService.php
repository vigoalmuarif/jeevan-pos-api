<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\User\Models\User;

class PosPinService
{
    public const IDLE_TIMEOUT_SECONDS = 600; // 10 menit

    private const SESSION_KEY = 'pos_pin_lock_state';

    /**
     * Aktifkan, ubah, atau nonaktifkan PIN keamanan kasir POS untuk user yang sedang login.
     *
     * @param  array<string, mixed>  $payload
     * @return array{pos_pin_enabled:bool,has_pos_pin:bool}
     */
    public function updatePin(User $user, array $payload, ?Request $request = null): array
    {
        $this->assertMerchantOwnership($user);

        $enabled = (bool) $payload['enabled'];
        $newPin = isset($payload['pin']) && $payload['pin'] !== '' ? (string) $payload['pin'] : null;
        $currentPin = isset($payload['current_pin']) && $payload['current_pin'] !== '' ? (string) $payload['current_pin'] : null;
        $currentPassword = isset($payload['current_password']) && $payload['current_password'] !== '' ? (string) $payload['current_password'] : null;

        if ($enabled) {
            if (! $user->hasPosPin() && $newPin === null) {
                throw ValidationException::withMessages([
                    'pin' => 'PIN 6 digit wajib diisi saat mengaktifkan kunci POS pertama kali.',
                ]);
            }

            if ($user->hasPosPin() && $newPin !== null) {
                $this->verifyCurrentCredential($user, $currentPin, $currentPassword);
            }

            if ($newPin !== null) {
                $user->pos_pin = Hash::make($newPin);
                $user->pos_pin_updated_at = now();
            }

            $user->pos_pin_enabled = true;
            $user->save();

            // Tandai sesi saat ini terbuka agar kasir bisa langsung lanjut kerja setelah menyalakan PIN
            if ($request !== null) {
                $this->markSessionUnlocked($request, $user);
            }
        } else {
            if ($user->pos_pin_enabled) {
                $this->verifyCurrentCredential($user, $currentPin, $currentPassword);
            }

            $user->pos_pin_enabled = false;
            $user->save();

            if ($request !== null) {
                $this->clearSessionState($request);
            }
        }

        return [
            'pos_pin_enabled' => (bool) $user->pos_pin_enabled,
            'has_pos_pin' => $user->hasPosPin(),
        ];
    }

    /**
     * Verifikasi PIN 6 digit untuk membuka kunci layar Kasir POS.
     *
     * @return array{verified:bool}
     */
    public function verifyPin(User $user, string $pin, ?Request $request = null): array
    {
        $this->assertMerchantOwnership($user);

        if (! $user->pos_pin_enabled || ! $user->hasPosPin()) {
            throw ValidationException::withMessages([
                'pin' => 'Fitur PIN POS belum diaktifkan pada akun ini.',
            ]);
        }

        if (! Hash::check($pin, (string) $user->pos_pin)) {
            throw ValidationException::withMessages([
                'pin' => 'PIN yang Anda masukkan salah.',
            ]);
        }

        if ($request !== null) {
            $this->markSessionUnlocked($request, $user);
        }

        return [
            'verified' => true,
        ];
    }

    /**
     * Fallback buka kunci layar POS menggunakan password akun (jika kasir lupa PIN).
     *
     * @return array{verified:bool,pos_pin_enabled:bool,has_pos_pin:bool}
     */
    public function unlockWithPassword(User $user, string $password, bool $disablePin = false, ?Request $request = null): array
    {
        $this->assertMerchantOwnership($user);

        if (! Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Password akun yang Anda masukkan salah.',
            ]);
        }

        if ($disablePin) {
            $user->pos_pin_enabled = false;
            $user->pos_pin = null;
            $user->pos_pin_updated_at = now();
            $user->save();

            if ($request !== null) {
                $this->clearSessionState($request);
            }
        } elseif ($request !== null) {
            $this->markSessionUnlocked($request, $user);
        }

        return [
            'verified' => true,
            'pos_pin_enabled' => (bool) $user->pos_pin_enabled,
            'has_pos_pin' => $user->hasPosPin(),
        ];
    }

    /**
     * Kunci sesi POS secara eksplisit di server (saat masuk POS, kunci manual, atau idle 10 menit).
     */
    public function markSessionLocked(Request $request, User $user): void
    {
        $this->assertMerchantOwnership($user);

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, [
            'user_id' => (int) $user->id,
            'merchant_id' => (int) $user->merchant_id,
            'locked' => true,
            'last_activity_at' => 0,
        ]);
    }

    /**
     * Tandai sesi POS terbuka setelah PIN/password terverifikasi di server.
     */
    public function markSessionUnlocked(Request $request, User $user): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, [
            'user_id' => (int) $user->id,
            'merchant_id' => (int) $user->merchant_id,
            'locked' => false,
            'last_activity_at' => now()->timestamp,
        ]);
    }

    /**
     * Periksa apakah sesi POS user saat ini terbuka dan belum melewati batas idle 10 menit.
     */
    public function isSessionUnlocked(Request $request, User $user): bool
    {
        if (! $user->pos_pin_enabled || ! $user->hasPosPin()) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        $state = $request->session()->get(self::SESSION_KEY);
        if (! is_array($state)) {
            return false;
        }

        if (($state['locked'] ?? true) === true) {
            return false;
        }

        if ((int) ($state['user_id'] ?? 0) !== (int) $user->id) {
            return false;
        }

        $merchantId = MerchantContext::id();
        if ($merchantId && (int) ($state['merchant_id'] ?? 0) !== (int) $merchantId) {
            return false;
        }

        $lastActivityAt = (int) ($state['last_activity_at'] ?? 0);
        if ($lastActivityAt <= 0 || (now()->timestamp - $lastActivityAt) >= self::IDLE_TIMEOUT_SECONDS) {
            $this->markSessionLocked($request, $user);

            return false;
        }

        return true;
    }

    /**
     * Perbarui timestamp aktivitas terakhir pada sesi POS yang sedang terbuka.
     */
    public function touchSessionActivity(Request $request, User $user): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $state = $request->session()->get(self::SESSION_KEY);
        if (! is_array($state) || ($state['locked'] ?? true) === true) {
            return;
        }

        $state['last_activity_at'] = now()->timestamp;
        $request->session()->put(self::SESSION_KEY, $state);
    }

    public function clearSessionState(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * Otorisasi tindakan sensitif POS (mis. void/pengurangan item yang sudah dikirim ke dapur)
     * menggunakan PIN 6 digit milik Owner / Manager / Supervisor pada merchant yang sama.
     *
     * @return User|null User supervisor yang menyetujui (atau current user jika owner & belum ada supervisor ber-PIN)
     */
    public function authorizeSupervisorAction(int $merchantId, ?string $pin, ?User $actingUser = null): ?User
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_pin')) {
            return $actingUser;
        }

        $cleanPin = $pin !== null ? trim($pin) : '';

        $supervisors = User::query()
            ->where('merchant_id', $merchantId)
            ->whereNotNull('pos_pin')
            ->get()
            ->filter(function (User $candidate) {
                if (method_exists($candidate, 'hasRole') && $candidate->hasRole('owner')) {
                    return true;
                }
                if (method_exists($candidate, 'can') && ($candidate->can('sales.void') || $candidate->can('sales.shift.manage'))) {
                    return true;
                }

                return false;
            });

        if ($cleanPin !== '') {
            foreach ($supervisors as $supervisor) {
                if (Hash::check($cleanPin, (string) $supervisor->pos_pin)) {
                    return $supervisor;
                }
            }

            throw ValidationException::withMessages([
                'supervisor_pin' => 'PIN Supervisor tidak valid atau tidak memiliki izin otorisasi void.',
            ]);
        }

        // Jika sudah ada minimal 1 Owner/Supervisor yang mengatur PIN POS, PIN wajib dimasukkan.
        if ($supervisors->isNotEmpty()) {
            throw ValidationException::withMessages([
                'supervisor_pin' => 'PIN Supervisor (6 digit) wajib dimasukkan untuk membatalkan item yang sudah dikirim ke dapur.',
            ]);
        }

        // Fallback aman untuk cafe kecil: jika belum ada supervisor yang mengatur PIN POS,
        // izinkan Owner / pemegang izin sales.void menyetujui langsung dengan alasan wajib.
        if ($actingUser !== null && (int) $actingUser->merchant_id === $merchantId) {
            $canSelfApprove = (method_exists($actingUser, 'hasRole') && $actingUser->hasRole('owner'))
                || (method_exists($actingUser, 'can') && $actingUser->can('sales.void'));

            if ($canSelfApprove) {
                return $actingUser;
            }
        }

        throw ValidationException::withMessages([
            'supervisor_pin' => 'Hanya Owner / Supervisor ber-PIN yang dapat menyetujui pembatalan item dapur.',
        ]);
    }

    private function assertMerchantOwnership(User $user): void
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        if ((int) $user->merchant_id !== (int) $merchantId) {
            abort(403, 'Anda tidak memiliki akses pada merchant ini.');
        }
    }

    private function verifyCurrentCredential(User $user, ?string $currentPin, ?string $currentPassword): void
    {
        if ($currentPin !== null) {
            if (! $user->hasPosPin() || ! Hash::check($currentPin, (string) $user->pos_pin)) {
                throw ValidationException::withMessages([
                    'current_pin' => 'PIN saat ini tidak sesuai.',
                ]);
            }

            return;
        }

        if ($currentPassword !== null) {
            if (! Hash::check($currentPassword, (string) $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Password akun tidak sesuai.',
                ]);
            }

            return;
        }

        throw ValidationException::withMessages([
            'current_pin' => 'Masukkan PIN saat ini atau password akun untuk konfirmasi keamanan.',
        ]);
    }
}
