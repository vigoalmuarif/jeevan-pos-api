<?php

namespace Modules\Permission\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Core\Abstracts\BaseController;
use Modules\Permission\Services\MenuService;

class MenuController extends BaseController
{
    public function __construct(
        private readonly MenuService $menuService,
    ) {}

    public function index()
    {
        $menus = $this->menuService->syncMenu();

        return $this->success($menus);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('permission::create');
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
        return view('permission::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('permission::edit');
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
