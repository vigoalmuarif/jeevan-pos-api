<?php

namespace Modules\Partner\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Abstracts\BaseController;
use Modules\Partner\Http\Requests\StoreSupplierRequest;
use Modules\Partner\Http\Requests\UpdateSupplierRequest;
use Modules\Partner\Models\Supplier;
use Modules\Partner\Services\SupplierService;
use Modules\Partner\Transformers\SupplierListResource;
use Modules\Partner\Transformers\SupplierResource;

class SupplierController extends BaseController
{
   public function __construct(
        private readonly SupplierService $supplierService
    ) {}

    public function index(Request $request)
    {
        $supplieres = $this->supplierService->getPaginated($request);

        return SupplierListResource::collection($supplieres);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return $this->success(
            new SupplierResource(
                $supplier->load(['village', 'district', 'regency', 'province'])
            )
        );
    }

    public function edit(Supplier $supplier): JsonResponse
    {
        return $this->success(
            new SupplierResource(
                $supplier->load(['region'])
            )
        );
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = $this->supplierService->create($request->all());

        return $this->success(new SupplierResource($supplier));
    }

    public function update(
        UpdateSupplierRequest $request,
        Supplier $supplier
    ): JsonResponse {
        $supplier = $this->supplierService->update(
            $supplier,
            $request->all()
        );

        return $this->success(
            new SupplierResource(
                $supplier->load(['region'])
            )
        );
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->supplierService->delete($supplier);

        return $this->noContent();
    }
}
