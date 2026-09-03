<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Http\Requests\SelectBranchRequest;
use Modules\Auth\Services\AuthService;
use Modules\Operational\Models\Branch;
use Modules\Operational\Transformers\BranchResource;
use Modules\Core\Abstracts\BaseController;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Transformers\MerchantResource;
use Modules\User\Transformers\UserResource;

class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());


        return $this->success($result, 'Login successful.');
    }

    public function me(Request $request, AuthService $authService): JsonResponse
    {
        $user = $authService->getAccessible($request->user('merchant'));

        return $this->success($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request);

        return $this->success(null, 'Logged out successfully.');
    }
}