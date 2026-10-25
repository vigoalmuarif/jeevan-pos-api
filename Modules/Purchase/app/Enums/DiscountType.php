<?php

namespace Modules\Purchase\Enums;

/**
 * Jenis diskon pada dokumen pembelian (header maupun item).
 * Disimpan di kolom string `discount_type`; NULL berarti tanpa diskon.
 */
enum DiscountType: string
{
    case Percent = 'percent'; // discount_value = persen (0-100)
    case Amount = 'amount';   // discount_value = nominal rupiah

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Persen (%)',
            self::Amount => 'Nominal (Rp)',
        };
    }
}