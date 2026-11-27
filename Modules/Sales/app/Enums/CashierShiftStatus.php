<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum CashierShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aktif',
            self::Closed => 'Ditutup',
        };
    }
}
