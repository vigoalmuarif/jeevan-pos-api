<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Abstracts\BaseController;
use Modules\Purchase\Http\Requests\StorePurchaseReturnRequest;
use Modules\Purchase\Http\Requests\UpdatePurchaseReturnRequest;
use Modules\Purchase\Http\Requests\VoidPurchaseReturnRequest;
use Modules\Purchase\Services\PurchaseReturnService;
use Modules\Purchase\Transformers\PurchaseReturnListResource;
use Modules\Purchase\Transformers\PurchaseReturnResource;

class PurchaseReturnController extends BaseController
{
    public function __construct(private readonly PurchaseReturnService $returns) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseReturnListResource::collection(
            $this->returns->getPaginated($request)
        );
    }

    public function returnableReceipts(Request $request): JsonResponse
    {
        return $this->success($this->returns->getReturnableReceipts($request));
    }

    public function returnableItems(int $receiptId): JsonResponse
    {
        return $this->success($this->returns->getReceiptReturnableItems($receiptId));
    }

    public function show(int $purchaseReturn): JsonResponse
    {
        return $this->success(new PurchaseReturnResource($this->returns->find($purchaseReturn)));
    }

    public function store(StorePurchaseReturnRequest $request): JsonResponse
    {
        $data = $request->validated();
        $return = $this->returns->create($data, $data['items']);

        return $this->created(
            new PurchaseReturnResource($this->returns->find($return->id)),
            'Draft retur pembelian berhasil dibuat.'
        );
    }

    public function update(int $purchaseReturn, UpdatePurchaseReturnRequest $request): JsonResponse
    {
        $data = $request->validated();
        $return = $this->returns->update($purchaseReturn, $data, $data['items']);

        return $this->success(
            new PurchaseReturnResource($this->returns->find($return->id)),
            'Draft retur pembelian berhasil diperbarui.'
        );
    }

    public function destroy(int $purchaseReturn): JsonResponse
    {
        $this->returns->delete($purchaseReturn);

        return $this->noContent();
    }

    public function post(int $purchaseReturn): JsonResponse
    {
        $return = $this->returns->post($purchaseReturn);

        return $this->success(
            new PurchaseReturnResource($this->returns->find($return->id)),
            'Retur pembelian berhasil diposting. Stok barang telah dikurangkan dan Debit Note diterbitkan.'
        );
    }

    public function void(int $purchaseReturn, VoidPurchaseReturnRequest $request): JsonResponse
    {
        $return = $this->returns->void($purchaseReturn, $request->validated('reason'));

        return $this->success(
            new PurchaseReturnResource($this->returns->find($return->id)),
            'Retur pembelian berhasil dibatalkan (void). Stok barang telah dikembalikan.'
        );
    }
}

