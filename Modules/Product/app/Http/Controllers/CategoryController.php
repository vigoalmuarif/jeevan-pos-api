<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Abstracts\BaseController;
use Modules\Product\Http\Requests\CategoryIndexRequest;
use Modules\Product\Http\Requests\StoreCategoryRequest;
use Modules\Product\Http\Requests\UpdateCategoryRequest;
use Modules\Product\Models\Category;
use Modules\Product\Services\CategoryService;
use Modules\Product\Transformers\CategoryResource;

class CategoryController extends BaseController
{
    public function __construct(protected CategoryService $categoryService) {}

    public function index(CategoryIndexRequest $request)
    {
        return CategoryResource::collection(
            $this->categoryService->getPaginated($request)
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
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->store($request->all());

        return $this->success(CategoryResource::make($category));
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
    public function update(UpdateCategoryRequest $request, Category $Category): JsonResponse 
    {
        $category = $this->categoryService->update($request->all(), $Category);

        return $this->success(CategoryResource::make($category));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $Category): JsonResponse
    {
        $Category->delete();

        return $this->noContent();
    }
}
