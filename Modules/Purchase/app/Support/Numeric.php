<?php

namespace Modules\Purchase\Support;

use Modules\Purchase\Exceptions\PurchaseRuleException;

/**
 * Normalisasi angka input (int / float / string dari JSON atau Form Request) menjadi
 * STRING numerik polos yang aman untuk bcmath. Float diformat tanpa notasi ilmiah
 * (mis. 1.0E-5) karena bcmath menolaknya. Validasi format akhir dilakukan PurchaseCalculator.
 */
final class Numeric
{
    public static function string(mixed $value, string $default = '0'): string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // 10 desimal cukup untuk semua kolom uang/kuantitas (maks. 6 desimal pada konversi).
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
        }

        if (is_string($value)) {
            return trim($value);
        }

        throw new PurchaseRuleException('Nilai angka tidak valid.');
    }
}