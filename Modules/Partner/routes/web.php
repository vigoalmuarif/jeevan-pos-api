<?php

use Illuminate\Support\Facades\Route;
use Modules\Partner\Http\Controllers\PartnerController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('partners', PartnerController::class)->names('partner');
});
