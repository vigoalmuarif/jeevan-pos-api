<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Abstracts\BaseController;
use Modules\Inventory\Http\Requests\StockMutationIndexRequest;
use Modules\Inventory\Services\StockMutationService;
use Modules\Inventory\Transformers\StockMutationListResource;

class StockMutationController extends BaseController
{
    public function __construct(protected StockMutationService $stockMutationService) {}

    public function index(StockMutationIndexRequest $request): AnonymousResourceCollection
    {
        return StockMutationListResource::collection(
            $this->stockMutationService->getPaginated($request)
        );
    }

    public function options(): JsonResponse
    {
        return $this->success($this->stockMutationService->getFilterOptions());
    }
}
