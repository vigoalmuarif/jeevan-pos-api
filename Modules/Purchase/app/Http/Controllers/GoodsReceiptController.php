<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Core\Abstracts\BaseController;
use Modules\Core\Helpers\MerchantContext;
use Modules\Purchase\Http\Requests\GoodsReceiptIndexRequest;
use Modules\Purchase\Http\Requests\StoreGoodsReceiptRequest;
use Modules\Purchase\Http\Requests\VoidGoodsReceiptRequest;
use Modules\Purchase\Services\GoodsReceiptService;
use Modules\Purchase\Transformers\GoodsReceiptListResource;
use Modules\Purchase\Transformers\GoodsReceiptResource;

class GoodsReceiptController extends BaseController
{
    public function __construct(private readonly GoodsReceiptService $goodsReceipts) {}

    public function index(GoodsReceiptIndexRequest $request): AnonymousResourceCollection
    {
        return GoodsReceiptListResource::collection(
            $this->goodsReceipts->getPaginated($request)
        );
    }

    public function options(): JsonResponse
    {
        return $this->success($this->goodsReceipts->getOptions());
    }

    public function productUnits(Request $request): JsonResponse
    {
        $merchantId = MerchantContext::id();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')
                    ->where('merchant_id', $merchantId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
        ]);

        return $this->success(
            $this->goodsReceipts->searchProductUnits(
                $validated['search'] ?? '',
                isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            )
        );
    }

    public function show(int $goodsReceipt): JsonResponse
    {
        return $this->success(new GoodsReceiptResource($this->goodsReceipts->find($goodsReceipt)));
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();
        $receipt = $this->goodsReceipts->create($data, $data['items']);

        return $this->created(
            new GoodsReceiptResource($this->goodsReceipts->find($receipt->id)),
            'Draft penerimaan barang berhasil dibuat.'
        );
    }

    public function update(StoreGoodsReceiptRequest $request, int $goodsReceipt): JsonResponse
    {
        $data = $request->validated();
        $this->goodsReceipts->update($this->goodsReceipts->find($goodsReceipt), $data, $data['items']);

        return $this->success(
            new GoodsReceiptResource($this->goodsReceipts->find($goodsReceipt)),
            'Draft penerimaan barang berhasil diperbarui.'
        );
    }

    public function destroy(int $goodsReceipt): JsonResponse
    {
        $this->goodsReceipts->delete($this->goodsReceipts->find($goodsReceipt));

        return $this->noContent();
    }

    public function post(int $goodsReceipt): JsonResponse
    {
        $this->goodsReceipts->post($this->goodsReceipts->find($goodsReceipt));

        return $this->success(
            new GoodsReceiptResource($this->goodsReceipts->find($goodsReceipt)),
            'Penerimaan barang berhasil diposting.'
        );
    }

    public function void(VoidGoodsReceiptRequest $request, int $goodsReceipt): JsonResponse
    {
        $this->goodsReceipts->void(
            $this->goodsReceipts->find($goodsReceipt),
            $request->validated('void_reason')
        );

        return $this->success(
            new GoodsReceiptResource($this->goodsReceipts->find($goodsReceipt)),
            'Penerimaan barang berhasil dibatalkan.'
        );
    }
}
