<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum SalesReturnStatus: string
{
    case Completed = 'completed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Selesai',
            self::Voided => 'Dibatalkan',
        };
    }
}
