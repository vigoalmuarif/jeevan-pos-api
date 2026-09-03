<?php

namespace Modules\Operational\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Operational\Models\Branch;
use Modules\Operational\Services\BranchDetailService;
use Modules\Core\Abstracts\BaseController;

class BranchDetailController extends BaseController
{
    public function __construct(
        private BranchDetailService $service
    ) {}


    public function operational(Branch $branch): JsonResponse
    {
        return response()->json([
            'data' => $this->service->getOperational($branch),
        ]);
    }

    public function resources(Branch $branch): JsonResponse
    {
        return response()->json([
            'data' => $this->service->getResources($branch),
        ]);
    }

    public function snapshot(Branch $branch): JsonResponse
    {
        // Guard: pastikan modul Sales aktif
        if (! $this->service->hasSalesModule()) {
            return response()->json(['data' => null], 200);
        }

        return response()->json([
            'data' => $this->service->getSnapshot($branch),
        ]);
    }
}
