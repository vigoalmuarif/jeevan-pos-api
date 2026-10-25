<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Product\Http\Requests\StoreVariantUnitRequest;
use Modules\Product\Http\Requests\UpdateVariantUnitRequest;
use Modules\Product\Services\ProductVariantUnitService;

class ProductVariantUnitController extends BaseController
{
    public function __construct(protected ProductVariantUnitService $service) {}
    public function store(StoreVariantUnitRequest $request, int $variant): JsonResponse { return $this->created($this->service->create($variant, $request->validated()), 'Satuan varian berhasil ditambahkan.'); }
    public function update(UpdateVariantUnitRequest $request, int $variantUnit): JsonResponse { return $this->success($this->service->update($variantUnit, $request->validated()), 'Satuan varian berhasil diperbarui.'); }
    public function destroy(int $variantUnit): JsonResponse { $this->service->delete($variantUnit); return $this->noContent(); }
}
