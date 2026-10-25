<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Modules\Core\Abstracts\BaseController;
use Modules\Inventory\Http\Requests\StockAdjustmentIndexRequest;
use Modules\Inventory\Http\Requests\StoreStockAdjustmentRequest;
use Modules\Inventory\Http\Requests\UpdateStockAdjustmentRequest;
use Modules\Inventory\Http\Requests\VoidStockAdjustmentRequest;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Inventory\Transformers\StockAdjustmentListResource;
use Modules\Inventory\Transformers\StockAdjustmentResource;

class StockAdjustmentController extends BaseController
{
    public function __construct(protected StockAdjustmentService $stockAdjustmentService) {}

    public function index(StockAdjustmentIndexRequest $request): AnonymousResourceCollection
    {
        return StockAdjustmentListResource::collection(
            $this->stockAdjustmentService->getPaginated($request)
        );
    }

    public function options(): JsonResponse
    {
        return $this->success($this->stockAdjustmentService->getFilterOptions());
    }

    public function productOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'in:damaged,expired,lost,found,correction,opening_stock,other'],
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->success($this->stockAdjustmentService->searchProductOptions(
            (int) $validated['warehouse_id'],
            $validated['reason'],
            $validated['search'] ?? '',
            (int) ($validated['per_page'] ?? 15),
            isset($validated['product_variant_id']) ? (int) $validated['product_variant_id'] : null,
        ));
    }

    public function show(int $stockAdjustment): JsonResponse
    {
        return $this->success(
            new StockAdjustmentResource($this->stockAdjustmentService->findForCurrentMerchant($stockAdjustment))
        );
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        return $this->created(
            new StockAdjustmentResource($this->stockAdjustmentService->create($request->validated())),
            'Draft adjustment stok berhasil dibuat.'
        );
    }

    public function update(UpdateStockAdjustmentRequest $request, int $stockAdjustment): JsonResponse
    {
        return $this->success(
            new StockAdjustmentResource(
                $this->stockAdjustmentService->update($stockAdjustment, $request->validated())
            ),
            'Draft adjustment stok berhasil diperbarui.'
        );
    }

    public function destroy(int $stockAdjustment): JsonResponse
    {
        $this->stockAdjustmentService->delete($stockAdjustment);

        return $this->noContent();
    }

    public function post(int $stockAdjustment): JsonResponse
    {
        return $this->success(
            new StockAdjustmentResource($this->stockAdjustmentService->post($stockAdjustment)),
            'Adjustment stok berhasil diposting.'
        );
    }

    public function void(VoidStockAdjustmentRequest $request, int $stockAdjustment): JsonResponse
    {
        return $this->success(
            new StockAdjustmentResource(
                $this->stockAdjustmentService->void($stockAdjustment, $request->validated('void_reason'))
            ),
            'Adjustment stok berhasil dibatalkan.'
        );
    }
}
