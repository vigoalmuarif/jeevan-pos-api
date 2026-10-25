<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Inventory\Http\Requests\WarehouseStockIndexRequest;
use Modules\Inventory\Services\WarehouseStockService;
use Modules\Inventory\Transformers\WarehouseStockListResource;

class WarehouseStockController extends BaseController
{
    public function __construct(protected WarehouseStockService $warehouseStockService) {}

    public function index(WarehouseStockIndexRequest $request): AnonymousResourceCollection
    {
        return WarehouseStockListResource::collection(
            $this->warehouseStockService->getPaginated($request)
        );
    }

    public function options(): JsonResponse
    {
        return $this->success($this->warehouseStockService->getFilterOptions());
    }
}
