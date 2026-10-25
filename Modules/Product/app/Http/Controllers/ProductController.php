<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Abstracts\BaseController;
use Modules\Product\Http\Requests\StoreProductRequest;
use Modules\Product\Http\Requests\StoreVariantRequest;
use Modules\Product\Http\Requests\UpdateVariantRequest;
use Modules\Product\Http\Requests\UpdateProductRequest;
use Modules\Product\Services\ProductService;
use Modules\Product\Transformers\ProductResource;

class ProductController extends BaseController
{
    public function __construct(protected ProductService $productService) {}

    public function index(\Illuminate\Http\Request $request): AnonymousResourceCollection
    {
        return ProductResource::collection($this->productService->getPaginated($request));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        return $this->created(new ProductResource($this->productService->create($request->validated())), 'Produk berhasil dibuat.');
    }

    public function show(int $product): JsonResponse
    {
        return $this->success(new ProductResource($this->productService->find($product)));
    }

    public function update(UpdateProductRequest $request, int $product): JsonResponse
    {
        return $this->success(new ProductResource($this->productService->update($product, $request->validated())), 'Produk berhasil diperbarui.');
    }

    public function destroy(int $product): JsonResponse
    {
        $this->productService->delete($product);

        return $this->noContent();
    }

    public function storeVariant(StoreVariantRequest $request, int $product): JsonResponse
    {
        return $this->created(
            new ProductResource($this->productService->addVariant($product, $request->validated())),
            'Varian berhasil ditambahkan.'
        );
    }

    public function destroyVariant(int $product, int $variant): JsonResponse
    {
        $this->productService->setVariantActive($product, $variant, false);

        return $this->noContent();
    }

    public function updateVariant(UpdateVariantRequest $request, int $product, int $variant): JsonResponse
    {
        return $this->success(new ProductResource($this->productService->updateVariant($product, $variant, $request->validated())), 'Varian berhasil diperbarui.');
    }
}
