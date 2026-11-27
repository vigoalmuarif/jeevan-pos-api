<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum ReturnFeeBearer: string
{
    case Customer = 'customer';
    case Merchant = 'merchant';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Pelanggan',
            self::Merchant => 'Toko',
        };
    }
}
