<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum SalesOrderType: string
{
    case Retail = 'retail';
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Retail => 'Reguler',
            self::DineIn => 'Dine-in',
            self::Takeaway => 'Takeaway',
            self::Delivery => 'Delivery',
        };
    }

    public function isFnb(): bool
    {
        return in_array($this, [self::DineIn, self::Takeaway, self::Delivery], true);
    }

    public function appliesServiceCharge(): bool
    {
        return $this === self::DineIn;
    }
}
