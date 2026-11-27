<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\CashierShiftController;
use Modules\Sales\Http\Controllers\KitchenDisplayController;
use Modules\Sales\Http\Controllers\PosCatalogController;
use Modules\Sales\Http\Controllers\PosPinController;
use Modules\Sales\Http\Controllers\PosSettingsController;
use Modules\Sales\Http\Controllers\SalesReturnController;
use Modules\Sales\Http\Controllers\SalesTransactionController;
use Modules\Sales\Http\Controllers\TableManagementController;

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
    'module:sales',
])->prefix('sales')->name('sales.')->group(function () {
    // POS Catalog & Options
    Route::get('pos/options', [PosCatalogController::class, 'options'])
        ->middleware('role_or_permission:owner|sales.pos.access|sales.view|sales.shift.manage');
    Route::get('pos/catalog', [PosCatalogController::class, 'catalog'])
        ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
    Route::get('pos/stocks', [PosCatalogController::class, 'stocks'])
        ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
    Route::get('pos/customers', [PosCatalogController::class, 'customers'])
        ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
    Route::post('pos/resolve-price', [PosCatalogController::class, 'resolvePrice'])
        ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
    Route::get('pos/sold-out', [PosCatalogController::class, 'soldOutMenu'])
        ->middleware('role_or_permission:owner|sales.pos.access|sales.shift.manage|sales.kds.access');
    Route::post('pos/sold-out/toggle', [PosCatalogController::class, 'toggleSoldOut'])
        ->middleware('role_or_permission:owner|sales.pos.access|sales.shift.manage|sales.kds.access');

    // POS Branch Settings (Per-Branch Multi-Industry Capabilities Override)
    Route::put('pos/settings/{branch}', [PosSettingsController::class, 'update'])
        ->whereNumber('branch')
        ->middleware('role_or_permission:owner|sales.shift.manage');

    // POS Security PIN (Per-User Cashier Lock)
    Route::put('pos/pin', [PosPinController::class, 'update'])
        ->middleware('role_or_permission:owner|sales.pos.access');
    Route::post('pos/pin/lock', [PosPinController::class, 'lock'])
        ->middleware('role_or_permission:owner|sales.pos.access');
    Route::post('pos/pin/verify', [PosPinController::class, 'verify'])
        ->middleware(['role_or_permission:owner|sales.pos.access', 'throttle:5,1']);
    Route::post('pos/pin/unlock-with-password', [PosPinController::class, 'unlockWithPassword'])
        ->middleware(['role_or_permission:owner|sales.pos.access', 'throttle:5,1']);

    // Cashier Shifts
    Route::get('shifts/active', [CashierShiftController::class, 'active'])
        ->middleware('role_or_permission:owner|sales.pos.access|sales.shift.manage');
    Route::get('shifts', [CashierShiftController::class, 'index'])
        ->middleware('role_or_permission:owner|sales.shift.manage|sales.view');
    Route::post('shifts/open', [CashierShiftController::class, 'open'])
        ->middleware(['role_or_permission:owner|sales.pos.access|sales.shift.manage', 'pos.pin.unlocked']);
    Route::get('shifts/{shift}', [CashierShiftController::class, 'show'])
        ->whereNumber('shift')
        ->middleware('role_or_permission:owner|sales.shift.manage|sales.view');
    Route::post('shifts/{shift}/movements', [CashierShiftController::class, 'addMovement'])
        ->whereNumber('shift')
        ->middleware(['role_or_permission:owner|sales.pos.access|sales.shift.manage', 'pos.pin.unlocked']);
    Route::post('shifts/{shift}/close', [CashierShiftController::class, 'close'])
        ->whereNumber('shift')
        ->middleware(['role_or_permission:owner|sales.pos.access|sales.shift.manage', 'pos.pin.unlocked']);

    // Sales Transactions (Checkout, Hold, History, Void)
    Route::get('transactions/held', [SalesTransactionController::class, 'held'])
        ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
    Route::get('transactions', [SalesTransactionController::class, 'index'])
        ->middleware('role_or_permission:owner|sales.view|sales.pos.access');
    Route::post('transactions', [SalesTransactionController::class, 'store'])
        ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
    Route::get('transactions/{transaction}', [SalesTransactionController::class, 'show'])
        ->whereNumber('transaction')
        ->middleware('role_or_permission:owner|sales.view|sales.pos.access');
    Route::post('transactions/{transaction}/complete', [SalesTransactionController::class, 'completeHeld'])
        ->whereNumber('transaction')
        ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
    Route::delete('transactions/{transaction}', [SalesTransactionController::class, 'destroyHeld'])
        ->whereNumber('transaction')
        ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
    Route::post('transactions/{transaction}/void', [SalesTransactionController::class, 'void'])
        ->whereNumber('transaction')
        ->middleware('role_or_permission:owner|sales.void');

    // Sales Returns (Retur Penjualan & Refund)
    Route::get('returns/returnable-transactions', [SalesReturnController::class, 'returnableTransactions'])
        ->middleware('role_or_permission:owner|sales.view|sales.pos.access');
    Route::get('returns/exchange-products', [SalesReturnController::class, 'exchangeProducts'])
        ->middleware('role_or_permission:owner|sales.view|sales.pos.access|sales.void');
    Route::get('returns', [SalesReturnController::class, 'index'])
        ->middleware('role_or_permission:owner|sales.view|sales.pos.access');
    Route::post('returns', [SalesReturnController::class, 'store'])
        ->middleware('role_or_permission:owner|sales.pos.access|sales.void');
    Route::get('returns/{salesReturn}', [SalesReturnController::class, 'show'])
        ->whereNumber('salesReturn')
        ->middleware('role_or_permission:owner|sales.view|sales.pos.access');
    Route::post('returns/{salesReturn}/void', [SalesReturnController::class, 'void'])
        ->whereNumber('salesReturn')
        ->middleware('role_or_permission:owner|sales.void');

    // Table Management & Open Bill (Gated by business module: table_management)
    Route::middleware('module:table_management')->group(function () {
        Route::get('tables', [TableManagementController::class, 'index'])
            ->middleware('role_or_permission:owner|sales.pos.access|sales.shift.manage|sales.view');
        Route::post('table-areas', [TableManagementController::class, 'storeArea'])
            ->middleware('role_or_permission:owner|sales.shift.manage');
        Route::patch('table-areas/{area}', [TableManagementController::class, 'updateArea'])
            ->whereNumber('area')
            ->middleware('role_or_permission:owner|sales.shift.manage');
        Route::delete('table-areas/{area}', [TableManagementController::class, 'destroyArea'])
            ->whereNumber('area')
            ->middleware('role_or_permission:owner|sales.shift.manage');
        Route::post('tables', [TableManagementController::class, 'storeTable'])
            ->middleware('role_or_permission:owner|sales.shift.manage');
        Route::patch('tables/{table}', [TableManagementController::class, 'updateTable'])
            ->whereNumber('table')
            ->middleware('role_or_permission:owner|sales.shift.manage');
        Route::patch('tables/{table}/status', [TableManagementController::class, 'updateStatus'])
            ->whereNumber('table')
            ->middleware(['role_or_permission:owner|sales.pos.access|sales.shift.manage', 'pos.pin.unlocked']);
        Route::delete('tables/{table}', [TableManagementController::class, 'destroyTable'])
            ->whereNumber('table')
            ->middleware('role_or_permission:owner|sales.shift.manage');
        Route::post('tables/{table}/order', [TableManagementController::class, 'saveOrder'])
            ->whereNumber('table')
            ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
        Route::post('tables/{table}/fire-course', [TableManagementController::class, 'fireCourse'])
            ->whereNumber('table')
            ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
        Route::post('tables/{table}/move', [TableManagementController::class, 'moveTable'])
            ->whereNumber('table')
            ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
        Route::post('tables/{table}/merge', [TableManagementController::class, 'mergeTables'])
            ->whereNumber('table')
            ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
        Route::post('tables/{table}/split', [TableManagementController::class, 'splitBill'])
            ->whereNumber('table')
            ->middleware(['role_or_permission:owner|sales.pos.access', 'pos.pin.unlocked']);
    });

    // Kitchen Display System (KDS) & Incremental KOT (Gated by business module: kitchen_display)
    Route::middleware('module:kitchen_display')->prefix('kds')->group(function () {
        Route::get('stations', [KitchenDisplayController::class, 'stations'])
            ->middleware('role_or_permission:owner|sales.pos.access|sales.shift.manage|sales.view');
        Route::post('stations', [KitchenDisplayController::class, 'storeStation'])
            ->middleware('role_or_permission:owner|sales.shift.manage');
        Route::patch('stations/{station}', [KitchenDisplayController::class, 'updateStation'])
            ->whereNumber('station')
            ->middleware('role_or_permission:owner|sales.shift.manage');
        Route::delete('stations/{station}', [KitchenDisplayController::class, 'destroyStation'])
            ->whereNumber('station')
            ->middleware('role_or_permission:owner|sales.shift.manage');
        Route::get('tickets', [KitchenDisplayController::class, 'tickets'])
            ->middleware('role_or_permission:owner|sales.pos.access|sales.shift.manage|sales.view');
        Route::patch('tickets/{ticket}/status', [KitchenDisplayController::class, 'updateTicketStatus'])
            ->whereNumber('ticket')
            ->middleware('role_or_permission:owner|sales.pos.access|sales.shift.manage');
        Route::post('transactions/{transaction}/dispatch', [KitchenDisplayController::class, 'dispatchTransaction'])
            ->whereNumber('transaction')
            ->middleware('role_or_permission:owner|sales.pos.access|sales.shift.manage');
    });
});
