<?php

namespace Modules\Permission\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Permission\Http\Requests\StoreRoleRequest;
use Modules\Permission\Http\Requests\UpdateRoleRequest;
use Modules\Permission\Transformers\RoleResource;
use Modules\Permission\Models\Role;
use Modules\Permission\Services\RoleService;

class RoleController extends BaseController
{
    public function __construct(
        private readonly RoleService $roleService
    ) {}

    public function index(): JsonResponse
    {
        $roles = $this->roleService->list();

        return $this->success(
            RoleResource::collection($roles)
        );
    }

    public function show(Role $role): JsonResponse
    {
        return $this->success(
            new RoleResource($role->load('permissions'))
        );
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated());

        return $this->created(new RoleResource($role));
    }

    public function update(
        UpdateRoleRequest $request,
        Role $role
    ): JsonResponse {
        $role = $this->roleService->update($role, $request->validated());

        return $this->success(new RoleResource($role));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roleService->delete($role);

        return $this->noContent();
    }
}