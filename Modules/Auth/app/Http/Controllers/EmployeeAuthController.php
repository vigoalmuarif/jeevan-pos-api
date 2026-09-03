<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Services\EmployeeAuthService;
use Modules\Core\Abstracts\BaseController;

class EmployeeAuthController extends BaseController
{
    public function __construct(
        private readonly EmployeeAuthService $authService
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            email    : $request->email,
            password : $request->password,
        );

        return $this->success($result, 'Login successful.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return $this->success(null, 'Logged out successfully.');
    }
}