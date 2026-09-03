<?php

namespace Modules\Operational\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operational\Http\Requests\StoreWarehouseRequest;
use Modules\Operational\Http\Requests\UpdateWarehouseRequest;
use Modules\Operational\Transformers\WarehouseResource;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\Warehouse;
use Modules\Operational\Services\WarehouseService;
use Modules\Core\Abstracts\BaseController;

class WarehouseController extends BaseController
{
    public function __construct(
        private readonly WarehouseService $warehouseService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $warehouses = $this->warehouseService->getPaginated($request);

        return $this->success(
            WarehouseResource::collection($warehouses)
        );
    }

    public function store(
        StoreWarehouseRequest $request,
        ?Branch $branch
    ): JsonResponse {
        $warehouse = $this->warehouseService->create(
            $branch,
            $request->validated()
        );

        return $this->created(new WarehouseResource($warehouse));
    }

    public function edit(
        ?Branch $branch,
        ?Warehouse $warehouse
    ): JsonResponse {

        return $this->success(
            new WarehouseResource(
                $warehouse->load('branch:id,code,name')
            )
        );
    }

    public function update(
        UpdateWarehouseRequest $request,
        Branch $branch,
        Warehouse $warehouse
    ): JsonResponse {
        $warehouse = $this->warehouseService->update(
            $warehouse,
            $request->validated()
        );

        return $this->success(new WarehouseResource($warehouse));
    }

    public function destroy(
        Branch $branch,
        Warehouse $warehouse
    ): JsonResponse {
        $this->warehouseService->delete($warehouse);

        return $this->noContent();
    }
}