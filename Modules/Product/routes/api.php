<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\BrandController;
use Modules\Product\Http\Controllers\CategoryController;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\UnitController;

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
])
    ->group(function () {
        Route::apiResource('products', ProductController::class)->names('products');

        Route::name('products.')
            ->group(function() {
                Route::apiResource('units', UnitController::class)->names('units');
                Route::apiResource('categories', CategoryController::class)->names('categories');
                Route::apiResource('brands', BrandController::class)->names('brands');
            });
    });
