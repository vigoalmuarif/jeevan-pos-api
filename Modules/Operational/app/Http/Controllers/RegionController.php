<?php

namespace Modules\Operational\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Operational\Services\RegionalService;
use Modules\Operational\Transformers\RegionResource;

class RegionController extends Controller
{
     public function __construct(
        private readonly RegionalService $regionService
    ) {}

    public function index(Request $request)
    {
        $regions = $this->regionService->getPaginated($request);

        return RegionResource::collection($regions);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('operational::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {}

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('operational::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('operational::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id) {}
}
