<?php

namespace Modules\Purchase\Enums;

/** Asal Purchase Request: diisi user, atau dibuat dari rekomendasi stok minimum. */
enum PurchaseRequestSource: string
{
    case Manual = 'manual';
    case LowStock = 'low_stock';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::LowStock => 'Stok Minimum',
        };
    }
}