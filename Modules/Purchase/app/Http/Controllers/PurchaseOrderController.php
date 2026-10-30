<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Abstracts\BaseController;
use Modules\Purchase\Http\Requests\CancelPurchaseOrderRequest;
use Modules\Purchase\Http\Requests\StorePurchaseOrderRequest;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Transformers\PurchaseOrderListResource;
use Modules\Purchase\Transformers\PurchaseOrderResource;

class PurchaseOrderController extends BaseController
{
    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseOrderListResource::collection(
            $this->orders->getPaginated($request)
        );
    }

    public function options(): JsonResponse
    {
        return $this->success($this->orders->getOptions());
    }

    public function receivable(Request $request): JsonResponse
    {
        $supplierId = $request->query('supplier_id') ? (int) $request->query('supplier_id') : null;

        return $this->success(
            PurchaseOrderResource::collection($this->orders->getReceivable($supplierId))
        );
    }

    public function show(int $id): JsonResponse
    {
        return $this->success(new PurchaseOrderResource($this->orders->find($id)));
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $order = $this->orders->create($data, $data['items']);

        return $this->created(
            new PurchaseOrderResource($this->orders->find($order->id)),
            'Draft Purchase Order berhasil dibuat.'
        );
    }

    public function update(StorePurchaseOrderRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $this->orders->update($this->orders->find($id), $data, $data['items']);

        return $this->success(
            new PurchaseOrderResource($this->orders->find($id)),
            'Draft Purchase Order berhasil diperbarui.'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $this->orders->delete($this->orders->find($id));

        return $this->noContent();
    }

    public function approve(int $id): JsonResponse
    {
        $order = $this->orders->approve($this->orders->find($id));

        return $this->success(
            new PurchaseOrderResource($this->orders->find($order->id)),
            'Purchase Order berhasil disetujui.'
        );
    }

    public function cancel(CancelPurchaseOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->orders->cancel($this->orders->find($id), $request->validated('reason'));

        return $this->success(
            new PurchaseOrderResource($this->orders->find($order->id)),
            'Purchase Order berhasil dibatalkan.'
        );
    }

    public function close(int $id): JsonResponse
    {
        $order = $this->orders->close($this->orders->find($id));

        return $this->success(
            new PurchaseOrderResource($this->orders->find($order->id)),
            'Purchase Order berhasil ditutup.'
        );
    }
}
