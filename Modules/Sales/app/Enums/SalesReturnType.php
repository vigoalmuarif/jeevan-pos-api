<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum SalesReturnType: string
{
    case Refund = 'refund';
    case Exchange = 'exchange';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Refund Dana',
            self::Exchange => 'Tukar Barang',
        };
    }
}
