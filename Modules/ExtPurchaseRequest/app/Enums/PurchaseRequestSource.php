<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Enums;

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
