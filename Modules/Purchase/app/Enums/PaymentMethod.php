<?php

namespace Modules\Purchase\Enums;

/**
 * Metode pembayaran ke supplier (kolom string `payment_method`).
 * Jika kelak ada modul Keuangan, enum ini dapat diganti master metode pembayaran.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Giro = 'giro';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Transfer => 'Transfer Bank',
            self::Giro => 'Giro / Cek',
            self::Other => 'Lainnya',
        };
    }
}