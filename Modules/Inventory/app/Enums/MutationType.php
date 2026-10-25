<?php

namespace Modules\Inventory\Enums;

/** Jenis mutasi stok yang berasal dari dokumen penerimaan barang. */
enum MutationType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case PurchaseReceiptVoid = 'purchase_receipt_void';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'Penerimaan Barang',
            self::PurchaseReceiptVoid => 'Pembatalan Penerimaan Barang',
        };
    }
}