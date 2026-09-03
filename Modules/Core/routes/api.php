<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\CoreController;
use Modules\Core\Http\Controllers\WilayahController;

Route::middleware(['auth'])->prefix('wilayah')->group(function () {
    Route::get('/', [WilayahController::class, 'index']);
    Route::get('/search', [WilayahController::class, 'search']);
    Route::get('/ancestors', [WilayahController::class, 'ancestors']);
});