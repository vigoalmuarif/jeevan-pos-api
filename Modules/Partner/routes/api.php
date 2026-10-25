<?php

use Illuminate\Support\Facades\Route;
use Modules\Partner\Http\Controllers\CustomerController;
use Modules\Partner\Http\Controllers\SupplierController;

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
])->group(function () {


    // Supplier
    Route::prefix('partner')->group(function () {
        Route::post(
            '/customers',
            [CustomerController::class, 'store']
        )->middleware('role_or_permission:owner|partner.customer.create');

        Route::get(
            '/customers',
            [CustomerController::class, 'index']
        )->middleware('role_or_permission:owner|partner.customer.view');

        Route::get(
            '/customers/{customer}/edit',
            [CustomerController::class, 'edit']
        )->middleware('role_or_permission:owner|partner.customer.update');

        Route::get(
            '/customers/{customer}',
            [CustomerController::class, 'show']
        )->middleware('role_or_permission:owner|partner.customer.view');

        Route::patch(
            '/customers/{customer}',
            [CustomerController::class, 'update']
        )->middleware('role_or_permission:owner|partner.customer.update');

        Route::delete(
            '/customers/{customer}',
            [CustomerController::class, 'destroy']
        )->middleware('role_or_permission:owner|partner.customer.delete');

        Route::get(
            '/suppliers',
            [SupplierController::class, 'index']
        )->middleware('role_or_permission:owner|partner.supplier.view');
        
        Route::post(
            '/suppliers',
            [SupplierController::class, 'store']
        )->middleware('role_or_permission:owner|partner.supplier.create');

        Route::get(
            '/suppliers/{supplier}/edit',
            [SupplierController::class, 'edit']
        )->middleware('role_or_permission:owner|partner.supplier.update');
        
        Route::patch(
            '/suppliers/{supplier}',
            [SupplierController::class, 'update']
        )->middleware('role_or_permission:owner|partner.supplier.update');


        Route::delete(
            '/suppliers/{supplier}',
            [SupplierController::class, 'destroy']
        )->middleware('role_or_permission:owner|partner.supplier.delete');
    });

});
