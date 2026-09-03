<?php

use Illuminate\Support\Facades\Route;
use Modules\Operational\Http\Controllers\BranchController;
use Modules\Operational\Http\Controllers\BranchDetailController;
use Modules\Operational\Http\Controllers\RegionController;
use Modules\Operational\Http\Controllers\WarehouseController;

/*
|------------------------------------------------------------------
| Branch & Warehouse Management
| ❌ resolve.branch tidak diperlukan
| Karena ini adalah management branch itu sendiri
|------------------------------------------------------------------
*/

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
])->group(function () {


    // Regional
    Route::prefix('regions')->group(function () {
        Route::get(
            '/',
            [RegionController::class, 'index']
        )->middleware('role_or_permission:owner|core.regional.view');
        
        Route::post(
            '/',
            [RegionController::class, 'store']
        )->middleware('role_or_permission:owner|core.regional.create');

        Route::get(
            '/{regional:slug}/edit',
            [RegionController::class, 'edit']
        )->middleware('role_or_permission:owner|core.regional.update');
        
        Route::patch(
            '/{regional:slug}',
            [RegionController::class, 'update']
        )->middleware('role_or_permission:owner|core.regional.update');
    });


    // Branch
    Route::prefix('branches')->group(function () {

        Route::middleware('role_or_permission:owner|core.branch.view')->group(function () {
            Route::get(
                '/',
                [BranchController::class, 'index']
            );
            Route::get(
                '/{branch}',
                [BranchController::class, 'show']
            );
        });

        Route::get(
            '/simple/combobox',
            [BranchController::class, 'forComboBox']
        );


        Route::post(
            '/',
            [BranchController::class, 'store']
        )->middleware('role_or_permission:owner|core.branch.q');

        Route::get(
            '/{branch:slug}/edit',
            [BranchController::class, 'edit']
        )->middleware('role_or_permission:owner|core.branch.update');

        Route::patch(
            '/{branch:slug}',
            [BranchController::class, 'update']
        )->middleware('role_or_permission:owner|core.branch.update');

        Route::delete(
            '/{branch:slug}',
            [BranchController::class, 'destroy']
        )->middleware('role_or_permission:owner|core.branch.delete');




        //=========== Warehouse — nested di bawah branch==========================================================================
        Route::middleware('role_or_permission:owner|core.warehouse.view')->group(function () {
            Route::get(
                '/{branch:slug}/warehouses',
                [WarehouseController::class, 'index']
            );
        });


        Route::post(
            '/{branch:slug}/warehouses',
            [WarehouseController::class, 'store']
        )->middleware('role_or_permission:owner|core.warehouse.create');

        Route::put(
            '/{branch:slug}/warehouses/{warehouse:slug}',
            [WarehouseController::class, 'update']
        )->middleware('role_or_permission:owner|core.warehouse.update');

        Route::delete(
            '/{branch:slug}/warehouses/{warehouse:slug}',
            [WarehouseController::class, 'destroy']
        )->middleware('role_or_permission:owner|core.warehouse.delete');


        // Tab endpoints
        Route::get('/{branch:slug}/operational', [BranchDetailController::class, 'operational']);
        Route::get('/{branch:slug}/resources', [BranchDetailController::class, 'resources']);
        Route::get('/{branch:slug}/snapshot', [BranchDetailController::class, 'snapshot']);
    });


    // warehouses
    Route::prefix('warehouses')->group(function () {
        Route::get(
            '/',
            [WarehouseController::class, 'index']
        )->middleware('role_or_permission:owner|core.warehouse.view');
        
        Route::post(
            '/',
            [WarehouseController::class, 'store']
        )->middleware('role_or_permission:owner|core.warehouse.create');

        Route::get(
            '/{warehouse:slug}/edit',
            [WarehouseController::class, 'edit']
        )->middleware('role_or_permission:owner|core.warehouse.update');
        
        Route::patch(
            '/{warehouse:slug}',
            [WarehouseController::class, 'update']
        )->middleware('role_or_permission:owner|core.warehouse.update');
    });
});
