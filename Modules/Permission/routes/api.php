<?php

use Illuminate\Support\Facades\Route;
use Modules\Permission\Http\Controllers\MenuController;
use Modules\Permission\Http\Controllers\PermissionController;
use Modules\Permission\Http\Controllers\RoleController;

Route::prefix('menus')->group(function (){
    Route::get('', [MenuController::class, 'index'])->name('menu');
});

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
    'resolve.branch',
])->prefix('api')->group(function () {

    // Role
    Route::middleware('can:role.view')->group(function () {
        Route::get('roles',       [RoleController::class, 'index']);
        Route::get('roles/{role}',[RoleController::class, 'show']);
    });

    Route::post('roles',
        [RoleController::class, 'store']
    )->middleware('can:role.create');

    Route::put('roles/{role}',
        [RoleController::class, 'update']
    )->middleware('can:role.update');

    Route::delete('roles/{role}',
        [RoleController::class, 'destroy']
    )->middleware('can:role.delete');

    // Permission
    Route::get('permissions',
        [PermissionController::class, 'index']
    )->middleware('can:role.view');

    Route::post('permissions',
        [PermissionController::class, 'store']
    )->middleware('can:role.create');

    Route::delete('permissions/{permission}',
        [PermissionController::class, 'destroy']
    )->middleware('can:role.delete');
});