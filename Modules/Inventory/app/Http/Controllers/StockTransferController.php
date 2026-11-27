<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Modules\Core\Abstracts\BaseController;
use Modules\Inventory\Http\Requests\CancelStockTransferRequest;
use Modules\Inventory\Http\Requests\ReceiveStockTransferRequest;
use Modules\Inventory\Http\Requests\StoreStockTransferRequest;
use Modules\Inventory\Services\StockTransferService;
use Modules\Inventory\Transformers\StockTransferListResource;
use Modules\Inventory\Transformers\StockTransferResource;

class StockTransferController extends BaseController
{
    public function __construct(
        protected StockTransferService $stockTransferService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return StockTransferListResource::collection($this->stockTransferService->getPaginated($request));
    }

    public function options(): JsonResponse
    {
        return $this->success($this->stockTransferService->getOptions());
    }

    public function productOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'destination_warehouse_id' => ['nullable', 'integer', 'min:1'],
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->success($this->stockTransferService->searchProductOptions(
            (int) $validated['warehouse_id'],
            $validated['search'] ?? '',
            (int) ($validated['per_page'] ?? 15),
            isset($validated['product_variant_id']) ? (int) $validated['product_variant_id'] : null,
            isset($validated['destination_warehouse_id']) ? (int) $validated['destination_warehouse_id'] : null,
        ));
    }

    public function inTransit(): AnonymousResourceCollection
    {
        return StockTransferResource::collection($this->stockTransferService->getInTransit());
    }

    public function show(int $stockTransfer): JsonResponse
    {
        return $this->success(new StockTransferResource($this->stockTransferService->findForCurrentMerchant($stockTransfer)));
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        return $this->created(
            new StockTransferResource($this->stockTransferService->create($request->validated(), $request->validated('items'))),
            'Draft transfer stok berhasil dibuat.',
        );
    }

    public function update(StoreStockTransferRequest $request, int $stockTransfer): JsonResponse
    {
        $data = $request->validated();

        return $this->success(new StockTransferResource(
            $this->stockTransferService->update($stockTransfer, $data, $data['items'])
        ), 'Draft transfer stok berhasil diperbarui.');
    }

    public function destroy(int $stockTransfer): JsonResponse
    {
        $this->stockTransferService->delete($stockTransfer);

        return $this->noContent();
    }

    public function ship(int $stockTransfer): JsonResponse
    {
        return $this->success(new StockTransferResource($this->stockTransferService->ship($stockTransfer)), 'Transfer stok berhasil dikirim.');
    }

    public function receive(ReceiveStockTransferRequest $request, int $stockTransfer): JsonResponse
    {
        $data = $request->validated();

        return $this->success(new StockTransferResource(
            $this->stockTransferService->receive($stockTransfer, $data['received'] ?? [], $data['receive_notes'] ?? null)
        ), 'Transfer stok berhasil diterima.');
    }

    public function cancel(CancelStockTransferRequest $request, int $stockTransfer): JsonResponse
    {
        return $this->success(new StockTransferResource(
            $this->stockTransferService->cancel($stockTransfer, $request->validated('reason'))
        ), 'Transfer stok berhasil dibatalkan.');
    }
}
