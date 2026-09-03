<?php

use Illuminate\Support\Facades\Route;
use Modules\Merchant\Http\Controllers\MerchantController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('merchants', MerchantController::class)->names('merchant');
});
