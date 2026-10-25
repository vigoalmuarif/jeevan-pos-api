<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Product\Http\Requests\StoreAssortmentGroupRequest;
use Modules\Product\Http\Requests\StoreBranchProductOverrideRequest;
use Modules\Product\Http\Requests\SyncAssortmentVariantsRequest;
use Modules\Product\Http\Requests\SyncBranchAssortmentsRequest;
use Modules\Product\Http\Requests\UpdateAssortmentGroupRequest;
use Modules\Product\Services\AssortmentService;

class AssortmentController extends BaseController
{
    public function __construct(protected AssortmentService $service) {}
    public function index(): JsonResponse { return $this->success($this->service->index()); }
    public function store(StoreAssortmentGroupRequest $request): JsonResponse { return $this->created($this->service->create($request->validated()), 'Grup produk cabang berhasil dibuat.'); }
    public function update(UpdateAssortmentGroupRequest $request, int $group): JsonResponse { return $this->success($this->service->update($group, $request->validated()), 'Grup produk cabang berhasil diperbarui.'); }
    public function destroy(int $group): JsonResponse { $this->service->delete($group); return $this->noContent(); }
    public function syncVariants(SyncAssortmentVariantsRequest $request, int $group): JsonResponse { return $this->success($this->service->syncVariants($group, $request->validated('variant_ids')), 'Isi grup produk berhasil diperbarui.'); }
    public function syncBranchGroups(SyncBranchAssortmentsRequest $request, int $branch): JsonResponse { return $this->success($this->service->syncBranchGroups($branch, $request->validated('assortment_group_ids')), 'Grup produk cabang berhasil diperbarui.'); }
    public function setOverride(StoreBranchProductOverrideRequest $request, int $branch, int $variant): JsonResponse { return $this->success($this->service->setOverride($branch, $variant, $request->validated()), 'Override produk cabang berhasil disimpan.'); }
    public function removeOverride(int $branch, int $variant): JsonResponse { return $this->success($this->service->removeOverride($branch, $variant), 'Override produk cabang berhasil dihapus.'); }
    public function activeProducts(int $branch): JsonResponse { return $this->success($this->service->activeProducts($branch)); }
    public function availability(int $branch): JsonResponse { return $this->success($this->service->availability($branch)); }
}
