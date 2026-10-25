<?php

namespace Modules\Purchase\Support;

use InvalidArgumentException;

/**
 * Jatuh tempo default faktur = tanggal faktur + tempo pembayaran supplier (hari).
 * Murni (tanpa database/Carbon) agar dapat diuji.
 */
final class DueDate
{
    /**
     * @param  mixed $termDays tempo supplier dalam hari; null / bukan angka / negatif = tidak ada default
     * @return string|null tanggal Y-m-d, atau null bila supplier tidak punya tempo yang valid
     */
    public static function fromTerm(string $invoiceDate, mixed $termDays): ?string
    {
        if ($termDays === null || ! is_numeric($termDays) || (int) $termDays < 0) {
            return null;
        }

        // '!' mereset jam ke 00:00 sehingga hasilnya murni tanggal.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($invoiceDate, 0, 10));
        if ($date === false) {
            throw new InvalidArgumentException("Tanggal faktur tidak valid: {$invoiceDate}");
        }

        return $date->modify('+'.(int) $termDays.' days')->format('Y-m-d');
    }
}