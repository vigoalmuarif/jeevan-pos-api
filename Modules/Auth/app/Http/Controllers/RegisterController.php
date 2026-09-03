<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Http\Requests\RegisterRequest;
use Modules\Auth\Services\RegisterService;
use Modules\Core\Abstracts\BaseController;
use Modules\User\Models\User;

class RegisterController extends BaseController
{
    public function __construct(
        private readonly RegisterService $registerService
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {

        $user = $this->registerService->register($request->validated());

        return $this->created($user, 'Registration successful.');
    }


    public function checkUsername(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string|regex:/^[a-z0-9_\-]+$/|unique:users,username',
        ],
        [
            'username.required' => 'Username wajib diisi.',
            'username.regex' => 'Username gak boleh pakai spasi',
            'username.unique' => 'Username sudah digunakan.',
        ]);

        $available = !User::where('username', $request->username)->exists();

        return $this->success([
            'username' => $request->username,
            'available' => $available,
        ]);
    }
}