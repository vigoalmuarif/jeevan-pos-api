<?php

namespace Modules\Inventory\Support;

use InvalidArgumentException;

/**
 * Aturan qty diterima dan selisih pada transfer stok (murni, tanpa database).
 * Semua nilai satuan dasar, string desimal 4 angka.
 */
final class TransferQuantities
{
    /**
     * Qty diterima yang dinormalisasi. Kosong (null / '') = diterima penuh sebesar yang dikirim.
     *
     * @throws InvalidArgumentException bila bukan angka tidak negatif (maks. 4 desimal) atau melebihi qty dikirim
     */
    public static function received(string $shipped, mixed $received): string
    {
        if ($received === null || $received === '') {
            return bcadd($shipped, '0', 4);
        }

        // Float dari JSON diformat tanpa notasi ilmiah; bcmath menolak format seperti 1.0E-5.
        $value = is_float($received)
            ? (rtrim(rtrim(sprintf('%.10F', $received), '0'), '.') ?: '0')
            : trim((string) $received);

        // Lebih dari 4 desimal akan terpotong DIAM-DIAM oleh bcmath, jadi ditolak.
        if (! preg_match('/^\d+(\.\d{1,4})?$/', $value)) {
            throw new InvalidArgumentException('Qty diterima harus angka tidak negatif dengan maksimal 4 desimal.');
        }

        $value = bcadd($value, '0', 4);
        if (bccomp($value, $shipped, 4) > 0) {
            throw new InvalidArgumentException('Qty diterima tidak boleh melebihi qty yang dikirim.');
        }

        return $value;
    }

    /** Selisih yang tidak sampai = dikirim - diterima (>= 0). */
    public static function variance(string $shipped, string $received): string
    {
        return bcsub($shipped, $received, 4);
    }
}