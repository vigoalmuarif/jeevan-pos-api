<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\BrandController;
use Modules\Product\Http\Controllers\CategoryController;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\PriceListController;
use Modules\Product\Http\Controllers\ProductPriceController;
use Modules\Product\Http\Controllers\ProductVariantUnitController;
use Modules\Product\Http\Controllers\AssortmentController;
use Modules\Product\Http\Controllers\UnitController;

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
])
    ->group(function () {
        Route::get('products', [ProductController::class, 'index'])->middleware('role_or_permission:owner|product.view');
        Route::post('products', [ProductController::class, 'store'])->middleware('role_or_permission:owner|product.create');
        Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->middleware('role_or_permission:owner|product.view');
        Route::post('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->middleware('role_or_permission:owner|product.update');
        Route::patch('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->middleware('role_or_permission:owner|product.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->middleware('role_or_permission:owner|product.delete');
        Route::post('products/{product}/variants', [ProductController::class, 'storeVariant'])->whereNumber('product')->middleware('role_or_permission:owner|product.create');
        Route::patch('products/{product}/variants/{variant}', [ProductController::class, 'updateVariant'])->whereNumber('product')->whereNumber('variant')->middleware('role_or_permission:owner|product.update');
        Route::delete('products/{product}/variants/{variant}', [ProductController::class, 'destroyVariant'])->whereNumber('product')->whereNumber('variant')->middleware('role_or_permission:owner|product.delete');
        Route::post('variants/{variant}/units', [ProductVariantUnitController::class, 'store'])->whereNumber('variant')->middleware('role_or_permission:owner|product.create');
        Route::patch('variant-units/{variantUnit}', [ProductVariantUnitController::class, 'update'])->whereNumber('variantUnit')->middleware('role_or_permission:owner|product.update');
        Route::delete('variant-units/{variantUnit}', [ProductVariantUnitController::class, 'destroy'])->whereNumber('variantUnit')->middleware('role_or_permission:owner|product.delete');

        Route::get('price-lists', [PriceListController::class, 'index'])->middleware('role_or_permission:owner|product.view');
        Route::post('price-lists', [PriceListController::class, 'store'])->middleware('role_or_permission:owner|product.create');
        Route::get('price-lists/{priceList}', [PriceListController::class, 'show'])->whereNumber('priceList')->middleware('role_or_permission:owner|product.view');
        Route::patch('price-lists/{priceList}', [PriceListController::class, 'update'])->whereNumber('priceList')->middleware('role_or_permission:owner|product.update');
        Route::delete('price-lists/{priceList}', [PriceListController::class, 'destroy'])->whereNumber('priceList')->middleware('role_or_permission:owner|product.delete');
        Route::get('variant-units/{variantUnit}/prices', [ProductPriceController::class, 'index'])->whereNumber('variantUnit')->middleware('role_or_permission:owner|product.view');
        Route::get('variant-units/{variantUnit}/resolved-price', [ProductPriceController::class, 'resolve'])->whereNumber('variantUnit')->middleware('role_or_permission:owner|product.view');
        Route::post('variant-units/{variantUnit}/prices', [ProductPriceController::class, 'store'])->whereNumber('variantUnit')->middleware('role_or_permission:owner|product.create');
        Route::patch('variant-units/{variantUnit}/prices/{price}', [ProductPriceController::class, 'update'])->whereNumber('variantUnit')->whereNumber('price')->middleware('role_or_permission:owner|product.update');
        Route::delete('variant-units/{variantUnit}/prices/{price}', [ProductPriceController::class, 'destroy'])->whereNumber('variantUnit')->whereNumber('price')->middleware('role_or_permission:owner|product.delete');

        Route::get('assortment-groups', [AssortmentController::class, 'index'])->middleware('role_or_permission:owner|product.view');
        Route::post('assortment-groups', [AssortmentController::class, 'store'])->middleware('role_or_permission:owner|product.create');
        Route::patch('assortment-groups/{group}', [AssortmentController::class, 'update'])->whereNumber('group')->middleware('role_or_permission:owner|product.update');
        Route::delete('assortment-groups/{group}', [AssortmentController::class, 'destroy'])->whereNumber('group')->middleware('role_or_permission:owner|product.delete');
        Route::put('assortment-groups/{group}/variants', [AssortmentController::class, 'syncVariants'])->whereNumber('group')->middleware('role_or_permission:owner|product.update');
        Route::put('branches/{branch}/assortment-groups', [AssortmentController::class, 'syncBranchGroups'])->whereNumber('branch')->middleware('role_or_permission:owner|product.update');
        Route::put('branches/{branch}/product-overrides/{variant}', [AssortmentController::class, 'setOverride'])->whereNumber('branch')->whereNumber('variant')->middleware('role_or_permission:owner|product.update');
        Route::delete('branches/{branch}/product-overrides/{variant}', [AssortmentController::class, 'removeOverride'])->whereNumber('branch')->whereNumber('variant')->middleware('role_or_permission:owner|product.update');
        Route::get('branches/{branch}/active-products', [AssortmentController::class, 'activeProducts'])->whereNumber('branch')->middleware('role_or_permission:owner|product.view');
        Route::get('branches/{branch}/product-availability', [AssortmentController::class, 'availability'])->whereNumber('branch')->middleware('role_or_permission:owner|product.view|product.update');

        Route::name('products.')
            ->group(function() {
                Route::apiResource('units', UnitController::class)->names('units');
                Route::apiResource('categories', CategoryController::class)->names('categories');
                Route::apiResource('brands', BrandController::class)->names('brands');
            });
    });
