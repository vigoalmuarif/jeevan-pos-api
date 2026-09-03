<?php

namespace Modules\Auth\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Abstracts\BaseService;
use Modules\User\Models\Employee;

class EmployeeAuthService extends BaseService
{
    public function login(
        string $email,
        string $password,
    ): array {
        $employee = Employee::where('email', $email)->first();

        if (!$employee || !Hash::check($password, $employee->password)) {
            throw ValidationException::withMessages([
                'email' => 'Email atau password salah',
            ]);
        }

        if (!$employee->isActive()) {
            throw ValidationException::withMessages([
                'email' => 'Oppss... Akun kamu udah ngga aktif.',
            ]);
        }

        auth()->guard('employee')->login($employee);

        $employee->update(['last_login_at' => now()]);

        return [
            'employee'     => $employee,
            'redirect_url' => config('app.admin_url'),
        ];
    }

    public function logout(Request $request): void
    {
        auth()->guard('employee')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}