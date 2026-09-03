<?php

use Illuminate\Support\Facades\Route;
use Modules\Operational\Http\Controllers\BranchController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('branches', BranchController::class)->names('branch');
});
