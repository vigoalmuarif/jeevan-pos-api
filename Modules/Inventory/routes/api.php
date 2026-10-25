<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\StockAdjustmentController;
use Modules\Inventory\Http\Controllers\StockMutationController;
use Modules\Inventory\Http\Controllers\WarehouseStockController;

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
])->group(function () {
    Route::get('warehouse-stocks', [WarehouseStockController::class, 'index'])
        ->middleware('role_or_permission:owner|inventory.view');
    Route::get('warehouse-stocks/options', [WarehouseStockController::class, 'options'])
        ->middleware('role_or_permission:owner|inventory.view');
        
    Route::get('stock-mutations', [StockMutationController::class, 'index'])
        ->middleware('role_or_permission:owner|inventory.movement.view');
    Route::get('stock-mutations/options', [StockMutationController::class, 'options'])
        ->middleware('role_or_permission:owner|inventory.movement.view');

    Route::prefix('stock-adjustments')->name('stock-adjustments.')->group(function () {
        Route::get('/options', [StockAdjustmentController::class, 'options'])
            ->middleware('role_or_permission:owner|inventory.adjustment.view');
        Route::get('/product-options', [StockAdjustmentController::class, 'productOptions'])
            ->middleware('role_or_permission:owner|inventory.adjustment.view');
        Route::get('/', [StockAdjustmentController::class, 'index'])
            ->middleware('role_or_permission:owner|inventory.adjustment.view');
        Route::get('/{stockAdjustment}', [StockAdjustmentController::class, 'show'])
            ->whereNumber('stockAdjustment')
            ->middleware('role_or_permission:owner|inventory.adjustment.view');
        Route::post('/', [StockAdjustmentController::class, 'store'])
            ->middleware('role_or_permission:owner|inventory.adjustment.create');
        Route::patch('/{stockAdjustment}', [StockAdjustmentController::class, 'update'])
            ->whereNumber('stockAdjustment')
            ->middleware('role_or_permission:owner|inventory.adjustment.create');
        Route::delete('/{stockAdjustment}', [StockAdjustmentController::class, 'destroy'])
            ->whereNumber('stockAdjustment')
            ->middleware('role_or_permission:owner|inventory.adjustment.create');
        Route::post('/{stockAdjustment}/post', [StockAdjustmentController::class, 'post'])
            ->whereNumber('stockAdjustment')
            ->middleware('role_or_permission:owner|inventory.adjustment.approve');
        Route::post('/{stockAdjustment}/void', [StockAdjustmentController::class, 'void'])
            ->whereNumber('stockAdjustment')
            ->middleware('role_or_permission:owner|inventory.adjustment.approve');
    });
});

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('inventories', InventoryController::class)->names('inventory');
});
