<?php

namespace Modules\Partner\Http\Controllers;

use Modules\Core\Abstracts\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Partner\Http\Requests\CustomerIndexRequest;
use Modules\Partner\Http\Requests\StoreCustomerRequest;
use Modules\Partner\Http\Requests\UpdateCustomerRequest;
use Modules\Partner\Services\CustomerService;
use Modules\Partner\Transformers\CustomerListResource;
use Modules\Partner\Transformers\CustomerResource;

class CustomerController extends BaseController
{
    public function __construct(protected CustomerService $customerService) {}

    public function index(CustomerIndexRequest $request): AnonymousResourceCollection
    {
        return CustomerListResource::collection(
            $this->customerService->getPaginated($request)
        );
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->create($request->validated());

        return $this->created(
            new CustomerListResource($customer),
            'Customer berhasil ditambahkan.'
        );
    }

    public function edit(int $customer): JsonResponse
    {
        return $this->success(
            new CustomerResource($this->customerService->findForCurrentMerchant($customer))
        );
    }

    public function show(int $customer): JsonResponse
    {
        return $this->success(
            new CustomerResource($this->customerService->findForCurrentMerchant($customer))
        );
    }

    public function update(UpdateCustomerRequest $request, int $customer): JsonResponse
    {
        return $this->success(
            new CustomerResource(
                $this->customerService->update($customer, $request->validated())
            ),
            'Customer berhasil diperbarui.'
        );
    }

    public function destroy(int $customer): JsonResponse
    {
        $this->customerService->delete($customer);

        return $this->noContent();
    }
}
