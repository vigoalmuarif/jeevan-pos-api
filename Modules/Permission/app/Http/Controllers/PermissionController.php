<?php

namespace Modules\Permission\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Core\Helpers\MerchantContext;
use Modules\Permission\Http\Requests\StorePermissionRequest;
use Modules\Permission\Transformers\PermissionResource;
use Modules\Permission\Models\Permission;
use Modules\Permission\Services\RoleService;

class PermissionController extends BaseController
{
    public function __construct(
        private readonly RoleService $roleService
    ) {}

    public function index(): JsonResponse
    {
        // Ambil system permission + custom permission merchant
        $permissions = Permission::where(function ($q) {
            $q->whereNull('merchant_id')
              ->orWhere('merchant_id', MerchantContext::id());
        })->where('guard_name', 'merchant')
          ->orderBy('group')
          ->orderBy('name')
          ->get()
          ->groupBy('group');

        return $this->success($permissions);
    }

}