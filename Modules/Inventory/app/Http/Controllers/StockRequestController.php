<?php

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Abstracts\BaseController;
use Modules\Inventory\Http\Requests\ApproveStockRequestRequest;
use Modules\Inventory\Http\Requests\CancelStockRequestRequest;
use Modules\Inventory\Http\Requests\ConvertStockRequestToTransferRequest;
use Modules\Inventory\Http\Requests\RejectStockRequestRequest;
use Modules\Inventory\Http\Requests\StockRequestProductOptionsRequest;
use Modules\Inventory\Http\Requests\StoreStockRequestRequest;
use Modules\Inventory\Http\Requests\UpdateStockRequestRequest;
use Modules\Inventory\Services\StockRequestService;
use Modules\Inventory\Transformers\StockRequestListResource;
use Modules\Inventory\Transformers\StockRequestResource;
use Modules\Inventory\Transformers\StockTransferResource;

class StockRequestController extends BaseController
{
    public function __construct(
        protected StockRequestService $service
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return StockRequestListResource::collection($this->service->getPaginated($request));
    }

    public function options(): JsonResponse
    {
        return response()->json($this->service->getOptions());
    }

    public function productOptions(StockRequestProductOptionsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->searchProductOptions($request->validated()),
        ]);
    }

    public function lowStockSuggestions(Request $request): JsonResponse
    {
        $warehouseId = (int) $request->query('destination_warehouse_id');
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;

        return response()->json([
            'data' => $this->service->getLowStockSuggestions($warehouseId, $branchId),
        ]);
    }

    public function store(StoreStockRequestRequest $request): JsonResponse
    {
        $created = $this->service->create($request->validated(), $request->validated('items'));

        return (new StockRequestResource($created))
            ->response()
            ->setStatusCode(201);
    }

    public function show(int $id): StockRequestResource
    {
        return new StockRequestResource($this->service->findForCurrentMerchant($id));
    }

    public function update(UpdateStockRequestRequest $request, int $id): StockRequestResource
    {
        return new StockRequestResource(
            $this->service->update($id, $request->validated(), $request->validated('items'))
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(['message' => 'Permintaan stok berhasil dihapus.']);
    }

    public function submit(int $id): StockRequestResource
    {
        return new StockRequestResource($this->service->submit($id));
    }

    public function approve(ApproveStockRequestRequest $request, int $id): StockRequestResource
    {
        return new StockRequestResource(
            $this->service->approve($id, $request->validated('approved_items'))
        );
    }

    public function reject(RejectStockRequestRequest $request, int $id): StockRequestResource
    {
        return new StockRequestResource(
            $this->service->reject($id, $request->validated('reason'))
        );
    }

    public function cancel(CancelStockRequestRequest $request, int $id): StockRequestResource
    {
        return new StockRequestResource(
            $this->service->cancel($id, $request->validated('reason'))
        );
    }

    public function convertToTransfer(ConvertStockRequestToTransferRequest $request, int $id): JsonResponse
    {
        $transfer = $this->service->convertToTransfer($id, $request->validated());

        return response()->json([
            'message' => 'Dokumen transfer pengiriman berhasil dibuat.',
            'data' => new StockTransferResource($transfer),
        ], 201);
    }
}

