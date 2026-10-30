<?php

namespace Modules\Inventory\Enums;

/** Jenis mutasi stok yang berasal dari dokumen penerimaan & retur pembelian barang. */
enum MutationType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case PurchaseReceiptVoid = 'purchase_receipt_void';
    case PurchaseReturn = 'purchase_return';
    case PurchaseReturnVoid = 'purchase_return_void';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'Penerimaan Barang',
            self::PurchaseReceiptVoid => 'Pembatalan Penerimaan Barang',
            self::PurchaseReturn => 'Retur Pembelian',
            self::PurchaseReturnVoid => 'Pembatalan Retur Pembelian',
        };
    }
}
