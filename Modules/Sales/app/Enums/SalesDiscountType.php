<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum SalesDiscountType: string
{
    case None = 'none';
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Tanpa Diskon',
            self::Fixed => 'Nominal (Rp)',
            self::Percentage => 'Persentase (%)',
        };
    }
}
