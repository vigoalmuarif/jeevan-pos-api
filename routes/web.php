<?php

use Illuminate\Support\Facades\Route;
use Modules\Permission\Http\Controllers\PermissionController;

Route::get('permissions/sync',
        [PermissionController::class, 'sync']
    );

Route::get('/', function () {
    return view('welcome');
});
