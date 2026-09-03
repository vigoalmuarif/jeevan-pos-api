<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Product\Http\Requests\BrandIndexRequest;
use Modules\Product\Http\Requests\StoreBrandRequest;
use Modules\Product\Http\Requests\UpdateBrandRequest;
use Modules\Product\Models\Brand;
use Modules\Product\Services\BrandService;
use Modules\Product\Transformers\BrandResource;

class BrandController extends BaseController
{
    public function __construct(protected BrandService $brandService) {}

    public function index(BrandIndexRequest $request)
    {
        return BrandResource::collection(
            $this->brandService->getPaginated($request)
        );
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('product::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = $this->brandService->store($request->all());

        return $this->success(BrandResource::make($brand));
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('product::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('product::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBrandRequest $request, Brand $Brand): JsonResponse
    {
        $brand = $this->brandService->update($request->all(), $Brand);

        return $this->success(BrandResource::make($brand));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Brand $Brand): JsonResponse
    {
        $Brand->delete();

        return $this->noContent();
    }
}
