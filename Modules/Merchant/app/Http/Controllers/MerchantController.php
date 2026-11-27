<?php

namespace Modules\Merchant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operational\Transformers\BranchResource;
use Modules\Core\Abstracts\BaseController;
use Modules\Merchant\Http\Requests\StoreMerchantRequest;
use Modules\Merchant\Http\Requests\UpdateMerchantRequest;
use Modules\Merchant\Transformers\MerchantResource;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Services\MerchantService;

class MerchantController extends BaseController
{
    public function __construct(
        private readonly MerchantService $merchantService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $merchants = $this->merchantService->list(
            $request->only(['search', 'status', 'per_page'])
        );

        return $this->success(
            MerchantResource::collection($merchants)
                ->response()
                ->getData(true)
        );
    }

    public function show(Merchant $merchant): JsonResponse
    {
        return $this->success(
            new MerchantResource(
                $merchant->loadCount(['users', 'branches'])
            )
        );
    }

    public function context(Request $request, Merchant $merchant): JsonResponse
    {
        $user = $request->user('merchant') ?? $request->user();
        $entitlementService = app(\Modules\Merchant\Services\ModuleEntitlementService::class);
        $entitledModules = $entitlementService->getEntitledModuleCodes($merchant);

        $permissions = [];
        $roles = [];
        if ($user) {
            $roles = $user->roles()->pluck('name')->values()->all();
            $permissions = ($user->hasRole('owner') || $user->is_owner)
                ? (app(\Modules\Permission\Services\PermissionCatalogService::class)->allNames() ?? [])
                : ($user->getAllPermissions()->pluck('name')->values()->all());
        }

        $data = [
            'merchant' => [
                'id' => $merchant->id,
                'ulid' => $merchant->ulid,
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'status' => $merchant->status,
                'currency' => $merchant->currency ?? 'IDR',
                'timezone' => $merchant->timezone ?? 'Asia/Jakarta',
            ],
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'roles' => $roles,
                'permissions' => $permissions,
            ] : null,
            'entitled_modules' => $entitledModules,
        ];

        return $this->success($data, 'Merchant context retrieved successfully.');
    }

    public function store(StoreMerchantRequest $request, MerchantService $service ): JsonResponse
    {
        $result = $service->create(
            $request->validated()
        );

        $data = [
            'merchant' => new MerchantResource($result['merchant']),
            'active_branch' => new BranchResource($result['branch'])
        ];

        return $this->created(
            $data,
            'Merchant created successfully.'
        );
    }

    public function update(
        UpdateMerchantRequest $request,
        Merchant $merchant
    ): JsonResponse {
        $merchant = $this->merchantService->update(
            $merchant,
            $request->validated()
        );

        return $this->success(new MerchantResource($merchant));
    }

    public function suspend(Merchant $merchant): JsonResponse
    {
        $merchant = $this->merchantService->suspend($merchant);

        return $this->success(
            new MerchantResource($merchant),
            'Merchant suspended successfully.'
        );
    }

    public function activate(Merchant $merchant): JsonResponse
    {
        $merchant = $this->merchantService->activate($merchant);

        return $this->success(
            new MerchantResource($merchant),
            'Merchant activated successfully.'
        );
    }

    public function destroy(Merchant $merchant): JsonResponse
    {
        $this->merchantService->delete($merchant);

        return $this->noContent();
    }
}