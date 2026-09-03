<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
    'resolve.branch',
])->prefix('api')->group(function () {

    Route::middleware('can:user.view')->group(function () {
        Route::get('users',         [UserController::class, 'index']);
        Route::get('users/{user}',  [UserController::class, 'show']);
    });

    Route::post('users',
        [UserController::class, 'store']
    )->middleware('can:user.create');

    Route::put('users/{user}',
        [UserController::class, 'update']
    )->middleware('can:user.update');

    Route::delete('users/{user}',
        [UserController::class, 'destroy']
    )->middleware('can:user.delete');

    Route::post('users/{user}/reset-password',
        [UserController::class, 'resetPassword']
    )->middleware('can:user.update');
});