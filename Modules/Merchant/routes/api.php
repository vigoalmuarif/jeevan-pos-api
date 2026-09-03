<?php

use Illuminate\Support\Facades\Route;
use Modules\Merchant\Http\Controllers\IndustryPackageController;
use Modules\Merchant\Http\Controllers\MerchantController;
use Modules\Merchant\Http\Controllers\MerchantSettingController;
use Modules\Merchant\Http\Controllers\ModuleController;




Route::get('industry-packages', [IndustryPackageController::class, 'index']);

/*
|------------------------------------------------------------------
| Employee Panel — app.jeevan.com
| Hanya employee yang bisa manage merchant
|------------------------------------------------------------------
*/
Route::middleware(['auth:employee'])
     ->prefix('api/employee')
     ->group(function () {

    Route::get('merchants',
        [MerchantController::class, 'index']
    );

    Route::get('merchants/{merchant}',
        [MerchantController::class, 'show']
    );

    Route::post('merchants',
        [MerchantController::class, 'store']
    );

    Route::put('merchants/{merchant}',
        [MerchantController::class, 'update']
    );

    Route::patch('merchants/{merchant}/suspend',
        [MerchantController::class, 'suspend']
    );

    Route::patch('merchants/{merchant}/activate',
        [MerchantController::class, 'activate']
    );

    Route::delete('merchants/{merchant}',
        [MerchantController::class, 'destroy']
    );
});


Route::middleware([
    'auth:merchant',
])->prefix('merchant')->group(function () {

    Route::post('store',
        [MerchantController::class, 'store']
    );

});

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
])->prefix('api/settings')->group(function () {

    Route::get('modules',
        [ModuleController::class, 'index']
    )->middleware('can:setting.view');

    Route::post('modules/enable',
        [ModuleController::class, 'enable']
    )->middleware('can:setting.update');

    Route::post('modules/disable',
        [ModuleController::class, 'disable']
    )->middleware('can:setting.update');

    Route::put('industry-package',
        [MerchantSettingController::class, 'updateBIndustryPackage']
    )->middleware('can:setting.update');
});