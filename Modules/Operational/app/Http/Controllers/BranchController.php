<?php

namespace Modules\Operational\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operational\Http\Requests\StoreBranchRequest;
use Modules\Operational\Http\Requests\UpdateBranchRequest;
use Modules\Operational\Transformers\BranchResource;
use Modules\Operational\Models\Branch;
use Modules\Operational\Services\BranchService;
use Modules\Core\Abstracts\BaseController;

class BranchController extends BaseController
{
    public function __construct(
        private readonly BranchService $branchService
    ) {}

    public function index(Request $request)
    {
        $branches = $this->branchService->getPaginated($request);

        return BranchResource::collection($branches);
    }

    public function forComboBox(Request $request): JsonResponse
    {
        $branches = Branch::select('id', 'name', 'code')
            ->when(isset($request->perPage), function($q) use ($request){
                $q->limit($request->perPage);
            })
            ->get();

        return $this->success($branches);
    }

    public function show(Branch $branch): JsonResponse
    {
        return $this->success(
            new BranchResource(
                $branch->load(['village', 'district', 'regency', 'province'])
            )
        );
    }

    public function edit(Branch $branch): JsonResponse
    {
        return $this->success(
            new BranchResource(
                $branch->load(['village', 'district', 'regency', 'province'])
            )
        );
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = $this->branchService->create($request->all());

        return $this->success(new BranchResource($branch));
    }

    public function update(
        UpdateBranchRequest $request,
        Branch $branch
    ): JsonResponse {
        $branch = $this->branchService->update(
            $branch,
            $request->all()
        );

        return $this->success(
            new BranchResource(
                $branch->load(['village', 'district', 'regency', 'province'])
            )
        );
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $this->branchService->delete($branch);

        return $this->noContent();
    }
}
