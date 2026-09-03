<?php

namespace Modules\User\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Abstracts\BaseController;
use Modules\User\Http\Requests\ResetPasswordRequest;
use Modules\User\Http\Requests\StoreUserRequest;
use Modules\User\Http\Requests\UpdateUserRequest;
use Modules\User\Transformers\UserResource;
use Modules\User\Models\User;
use Modules\User\Services\UserService;

class UserController extends BaseController
{
    public function __construct(
        private readonly UserService $userService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->list(
            $request->only(['search', 'is_active', 'role', 'per_page'])
        );

        return $this->success(
            UserResource::collection($users)
                        ->response()
                        ->getData(true)
        );
    }

    public function show(User $user): JsonResponse
    {
        return $this->success(
            new UserResource($user->load(['roles', 'branches']))
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return $this->created(new UserResource($user));
    }

    public function update(
        UpdateUserRequest $request,
        User $user
    ): JsonResponse {
        $user = $this->userService->update($user, $request->validated());

        return $this->success(new UserResource($user));
    }

    public function destroy(User $user): JsonResponse
    {
        $this->userService->delete($user);

        return $this->noContent();
    }

    public function resetPassword(
        ResetPasswordRequest $request,
        User $user
    ): JsonResponse {
        $this->userService->resetPassword($user, $request->password);

        return $this->success(null, 'Password reset successfully.');
    }
}