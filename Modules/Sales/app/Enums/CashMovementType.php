<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum CashMovementType: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';

    public function label(): string
    {
        return match ($this) {
            self::CashIn => 'Kas Masuk',
            self::CashOut => 'Kas Keluar',
        };
    }
}
