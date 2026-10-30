<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ExtPurchaseRequest\Http\Controllers\PurchaseRequestController;

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
])->prefix('purchase-requests')->name('purchase-requests.')->group(function () {
    Route::get('/options', [PurchaseRequestController::class, 'options'])
        ->middleware('role_or_permission:owner|purchase.request.view|purchase.request.create|purchase.request.update');

    Route::get('/approved-items', [PurchaseRequestController::class, 'approvedItems'])
        ->middleware('role_or_permission:owner|purchase.request.view|purchase.request.convert|purchase.order.create');

    Route::get('/approved-items/options', [PurchaseRequestController::class, 'approvedItemOptions'])
        ->middleware('role_or_permission:owner|purchase.request.view|purchase.request.convert|purchase.order.create');

    Route::post('/convert-to-orders', [PurchaseRequestController::class, 'convertToOrders'])
        ->middleware('role_or_permission:owner|purchase.request.convert|purchase.order.create');

    Route::get('/', [PurchaseRequestController::class, 'index'])
        ->middleware('role_or_permission:owner|purchase.request.view');

    Route::post('/', [PurchaseRequestController::class, 'store'])
        ->middleware('role_or_permission:owner|purchase.request.create');

    Route::get('/{purchaseRequest}', [PurchaseRequestController::class, 'show'])
        ->whereNumber('purchaseRequest')
        ->middleware('role_or_permission:owner|purchase.request.view');

    Route::put('/{purchaseRequest}', [PurchaseRequestController::class, 'update'])
        ->whereNumber('purchaseRequest')
        ->middleware('role_or_permission:owner|purchase.request.update');

    Route::delete('/{purchaseRequest}', [PurchaseRequestController::class, 'destroy'])
        ->whereNumber('purchaseRequest')
        ->middleware('role_or_permission:owner|purchase.request.delete');

    Route::post('/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])
        ->whereNumber('purchaseRequest')
        ->middleware('role_or_permission:owner|purchase.request.create|purchase.request.update');

    Route::post('/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])
        ->whereNumber('purchaseRequest')
        ->middleware('role_or_permission:owner|purchase.request.approve');

    Route::post('/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])
        ->whereNumber('purchaseRequest')
        ->middleware('role_or_permission:owner|purchase.request.approve|purchase.request.reject');

    Route::post('/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel'])
        ->whereNumber('purchaseRequest')
        ->middleware('role_or_permission:owner|purchase.request.cancel');

    Route::post('/{purchaseRequest}/close', [PurchaseRequestController::class, 'close'])
        ->whereNumber('purchaseRequest')
        ->middleware('role_or_permission:owner|purchase.request.close');
});
