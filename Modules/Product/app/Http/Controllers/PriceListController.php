<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Product\Http\Requests\StorePriceListRequest;
use Modules\Product\Http\Requests\UpdatePriceListRequest;
use Modules\Product\Services\PriceListService;

class PriceListController extends BaseController
{
    public function __construct(protected PriceListService $service) {}

    public function index(): JsonResponse { return $this->success($this->service->all()); }
    public function store(StorePriceListRequest $request): JsonResponse { return $this->created($this->service->create($request->validated()), 'Daftar harga berhasil dibuat.'); }
    public function show(int $priceList): JsonResponse { return $this->success($this->service->find($priceList)); }
    public function update(UpdatePriceListRequest $request, int $priceList): JsonResponse { return $this->success($this->service->update($priceList, $request->validated()), 'Daftar harga berhasil diperbarui.'); }
    public function destroy(int $priceList): JsonResponse { $this->service->deactivate($priceList); return $this->noContent(); }
}
