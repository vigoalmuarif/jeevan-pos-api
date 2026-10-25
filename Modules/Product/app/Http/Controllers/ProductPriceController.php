<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Abstracts\BaseController;
use Modules\Product\Http\Requests\StoreProductPriceRequest;
use Modules\Product\Http\Requests\UpdateProductPriceRequest;
use Modules\Product\Services\ProductPriceService;
use Modules\Product\Services\PriceResolverService;

class ProductPriceController extends BaseController
{
    public function __construct(protected ProductPriceService $service, protected PriceResolverService $resolver) {}

    public function index(int $variantUnit): JsonResponse { return $this->success($this->service->list($variantUnit)); }
    public function store(StoreProductPriceRequest $request, int $variantUnit): JsonResponse { return $this->created($this->service->create($variantUnit, $request->validated()), 'Harga berhasil dibuat.'); }
    public function update(UpdateProductPriceRequest $request, int $variantUnit, int $price): JsonResponse { return $this->success($this->service->update($variantUnit, $price, $request->validated()), 'Harga berhasil diperbarui.'); }
    public function destroy(int $variantUnit, int $price): JsonResponse { $this->service->delete($variantUnit, $price); return $this->noContent(); }

    public function resolve(Request $request, int $variantUnit): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'price_list_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        return $this->success($this->resolver->resolve(
            $variantUnit,
            (float) $data['quantity'],
            isset($data['price_list_id']) ? (int) $data['price_list_id'] : null,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
        ));
    }
}
