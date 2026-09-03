<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\EmployeeAuthController;
use Modules\Auth\Http\Controllers\RegisterController;
use Modules\Auth\Http\Controllers\SetupWizardController;

/*
|------------------------------------------------------------------
| Merchant Auth — domain utama (jeevan.com)
|------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {

    // Public
    Route::post('register',              [RegisterController::class, 'register']);
    Route::get('register/check-slug',    [RegisterController::class, 'checkSlug']);
    Route::post('login',                 [AuthController::class, 'login'])->middleware('web');
    Route::post('select-branch',         [AuthController::class, 'selectBranch']);

    // Authenticated
    Route::middleware(['auth:merchant'])->group(function () {
        Route::post('setup-wizard', [SetupWizardController::class, 'setup']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

/*
|------------------------------------------------------------------
| Employee Auth — admin panel (office.jeevan.com)
|------------------------------------------------------------------
*/
Route::prefix('employee/auth')->group(function () {

    // Public
    Route::post('login', [EmployeeAuthController::class, 'login']);

    // Authenticated
    Route::middleware(['auth:employee'])->group(function () {
        Route::post('logout', [EmployeeAuthController::class, 'logout']);
        Route::get('me', [EmployeeAuthController::class, 'me']);
    });
});