<?php

namespace Modules\Purchase\Enums;

enum ReturnReason: string
{
    case Damaged = 'damaged';
    case Expired = 'expired';
    case WrongItem = 'wrong_item';
    case Defective = 'defective';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Barang Rusak / Cacat Fisik',
            self::Expired => 'Kadaluwarsa',
            self::WrongItem => 'Salah Kirim Barang',
            self::Defective => 'Kualitas Tidak Sesuai Spesifikasi',
            self::Other => 'Lainnya',
        };
    }
}

