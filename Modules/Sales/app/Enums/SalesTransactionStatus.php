<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum SalesTransactionStatus: string
{
    case Held = 'held';
    case Completed = 'completed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Ditahan',
            self::Completed => 'Selesai',
            self::Voided => 'Dibatalkan',
        };
    }
}
