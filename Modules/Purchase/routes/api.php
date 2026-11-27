<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchase\Http\Controllers\GoodsReceiptController;
use Modules\Purchase\Http\Controllers\PurchaseInvoiceController;
use Modules\Purchase\Http\Controllers\PurchaseOrderController;
use Modules\Purchase\Http\Controllers\PurchasePaymentController;
use Modules\Purchase\Http\Controllers\PurchaseReturnController;

Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
])->prefix('goods-receipts')->name('goods-receipts.')->group(function () {
    Route::get('/options', [GoodsReceiptController::class, 'options'])
        ->middleware('role_or_permission:owner|purchase.receipt.view|purchase.receipt.create|purchase.receipt.update');
    Route::get('/product-units', [GoodsReceiptController::class, 'productUnits'])
        ->middleware('role_or_permission:owner|purchase.receipt.view|purchase.receipt.create|purchase.receipt.update');
    Route::get('/', [GoodsReceiptController::class, 'index'])
        ->middleware('role_or_permission:owner|purchase.receipt.view');
    Route::post('/', [GoodsReceiptController::class, 'store'])
        ->middleware('role_or_permission:owner|purchase.receipt.create');
    Route::get('/{goodsReceipt}', [GoodsReceiptController::class, 'show'])
        ->whereNumber('goodsReceipt')
        ->middleware('role_or_permission:owner|purchase.receipt.view');
    Route::patch('/{goodsReceipt}', [GoodsReceiptController::class, 'update'])
        ->whereNumber('goodsReceipt')
        ->middleware('role_or_permission:owner|purchase.receipt.update');
    Route::delete('/{goodsReceipt}', [GoodsReceiptController::class, 'destroy'])
        ->whereNumber('goodsReceipt')
        ->middleware('role_or_permission:owner|purchase.receipt.delete');
    Route::post('/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])
        ->whereNumber('goodsReceipt')
        ->middleware('role_or_permission:owner|purchase.receipt.post');
    Route::post('/{goodsReceipt}/void', [GoodsReceiptController::class, 'void'])
        ->whereNumber('goodsReceipt')
        ->middleware('role_or_permission:owner|purchase.receipt.void');
});



/*
 * Rute faktur pembelian. Muat file ini DI DALAM grup rute terproteksi yang sudah Anda punya
 * (auth guard merchant + ResolveMerchantContext), mis. di Routes/api.php module Purchase:
 *
 *     Route::middleware([...middleware terproteksi Anda...])->group(function () {
 *         require __DIR__.'/purchase_invoices.php';
 *     });
 *
 * Izin memakai pola middleware role_or_permission yang juga digunakan route modul Purchase lain,
 * dengan role owner sebagai akses penuh dan permission spesifik untuk tiap aksi.
 */
Route::middleware([
    'resolve.merchant',
    'merchant.active',
    'auth:merchant',
])->prefix('purchase')->group(function () {
    // Purchase Orders
    Route::get('orders/options', [PurchaseOrderController::class, 'options'])
        ->middleware('role_or_permission:owner|purchase.order.view|purchase.order.create|purchase.order.update');
    Route::get('orders/receivable', [PurchaseOrderController::class, 'receivable'])
        ->middleware('role_or_permission:owner|purchase.order.view|purchase.receipt.create');
    Route::get('orders', [PurchaseOrderController::class, 'index'])
        ->middleware('role_or_permission:owner|purchase.order.view');
    Route::post('orders', [PurchaseOrderController::class, 'store'])
        ->middleware('role_or_permission:owner|purchase.order.create');
    Route::get('orders/{id}', [PurchaseOrderController::class, 'show'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.order.view');
    Route::put('orders/{id}', [PurchaseOrderController::class, 'update'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.order.update');
    Route::delete('orders/{id}', [PurchaseOrderController::class, 'destroy'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.order.delete');
    Route::post('orders/{id}/approve', [PurchaseOrderController::class, 'approve'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.order.approve');
    Route::post('orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.order.cancel');
    Route::post('orders/{id}/close', [PurchaseOrderController::class, 'close'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.order.close');

    // Didaftarkan lebih dulu agar tidak tertangkap oleh invoices/{id}.
    Route::get('goods-receipts/invoiceable', [PurchaseInvoiceController::class, 'invoiceable'])
        ->middleware('role_or_permission:owner|purchase.invoice.create');

    Route::get('invoices', [PurchaseInvoiceController::class, 'index'])
        ->middleware('role_or_permission:owner|purchase.invoice.view');
    Route::post('invoices', [PurchaseInvoiceController::class, 'store'])
        ->middleware('role_or_permission:owner|purchase.invoice.create');

    Route::get('invoices/{id}', [PurchaseInvoiceController::class, 'show'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.invoice.view');
    Route::put('invoices/{id}', [PurchaseInvoiceController::class, 'update'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.invoice.update');
    Route::delete('invoices/{id}', [PurchaseInvoiceController::class, 'destroy'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.invoice.delete');

    Route::post('invoices/{id}/post', [PurchaseInvoiceController::class, 'post'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.invoice.post');
    Route::post('invoices/{id}/void', [PurchaseInvoiceController::class, 'void'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.invoice.void');



    // Didaftarkan lebih dulu agar tidak tertangkap oleh payments/{id}.
    Route::get('payments/payable-invoices', [PurchasePaymentController::class, 'payableInvoices'])
        ->middleware('role_or_permission:owner|purchase.payment.create');

    Route::get('payments', [PurchasePaymentController::class, 'index'])
        ->middleware('role_or_permission:owner|purchase.payment.view');
    Route::post('payments', [PurchasePaymentController::class, 'store'])
        ->middleware('role_or_permission:owner|purchase.payment.create');

    Route::get('payments/{id}', [PurchasePaymentController::class, 'show'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.payment.view');
    Route::post('payments/{id}/void', [PurchasePaymentController::class, 'void'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.payment.void');

    // Purchase Returns (DEC-10)
    Route::get('returns/returnable-receipts', [PurchaseReturnController::class, 'returnableReceipts'])
        ->middleware('role_or_permission:owner|purchase.return.create');
    Route::get('returns/returnable-items/{receiptId}', [PurchaseReturnController::class, 'returnableItems'])
        ->whereNumber('receiptId')
        ->middleware('role_or_permission:owner|purchase.return.create');
    Route::get('returns', [PurchaseReturnController::class, 'index'])
        ->middleware('role_or_permission:owner|purchase.return.view');
    Route::post('returns', [PurchaseReturnController::class, 'store'])
        ->middleware('role_or_permission:owner|purchase.return.create');
    Route::get('returns/{id}', [PurchaseReturnController::class, 'show'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.return.view');
    Route::put('returns/{id}', [PurchaseReturnController::class, 'update'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.return.update');
    Route::delete('returns/{id}', [PurchaseReturnController::class, 'destroy'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.return.delete');
    Route::post('returns/{id}/post', [PurchaseReturnController::class, 'post'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.return.post');
    Route::post('returns/{id}/void', [PurchaseReturnController::class, 'void'])
        ->whereNumber('id')->middleware('role_or_permission:owner|purchase.return.void');
});
