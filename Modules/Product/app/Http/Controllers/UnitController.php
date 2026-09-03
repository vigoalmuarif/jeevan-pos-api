<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Abstracts\BaseController;
use Modules\Product\Http\Requests\StoreUnitRequest;
use Modules\Product\Http\Requests\UnitIndexRequest;
use Modules\Product\Http\Requests\UpdateUnitRequest;
use Modules\Product\Models\Unit;
use Modules\Product\Services\UnitService;
use Modules\Product\Transformers\UnitResource;

class UnitController extends BaseController
{
    public function __construct(protected UnitService $unitService) {}

    public function index(UnitIndexRequest $request)
    {
        return UnitResource::collection(
            $this->unitService->getPaginated($request)
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
    public function store(StoreUnitRequest $request): JsonResponse
    {
        $unit = $this->unitService->store($request->validated());

        return $this->success(UnitResource::make($unit));
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
    public function update(UpdateUnitRequest $request, Unit $unit): JsonResponse 
    {
        $unit = $this->unitService->update($request->validated(), $unit);

        return $this->success(UnitResource::make($unit));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Unit $unit): JsonResponse
    {
        $unit->delete();

        return $this->noContent();
    }
}
