<?php

namespace Modules\Operational\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Abstracts\BaseController;
use Modules\Operational\Http\Requests\StoreRegionalRequest;
use Modules\Operational\Http\Requests\UpdateRegionalRequest;
use Modules\Operational\Models\Region;
use Modules\Operational\Services\RegionalService;
use Modules\Operational\Transformers\RegionResource;

class RegionController extends BaseController
{
     public function __construct(
        private readonly RegionalService $regionService
    ) {}

    public function index(Request $request)
    {
        $regions = $this->regionService->getPaginated($request);

        return RegionResource::collection($regions);
    }

    public function options(): JsonResponse
    {
        return $this->success($this->regionService->getOptions());
    }

    public function store(StoreRegionalRequest $request): JsonResponse
    {
        $region = $this->regionService->create($request->validated());

        return $this->created(new RegionResource($region));
    }

    public function edit(Region $regional): JsonResponse
    {
        return $this->success(new RegionResource($regional->load('parent:id,name,code,type')));
    }

    public function update(UpdateRegionalRequest $request, Region $regional): JsonResponse
    {
        $region = $this->regionService->update($regional, $request->validated());

        return $this->success(new RegionResource($region));
    }

    public function destroy(Region $regional): JsonResponse
    {
        $this->regionService->delete($regional);

        return $this->noContent();
    }
}
