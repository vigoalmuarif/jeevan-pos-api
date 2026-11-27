<?php

namespace Modules\Core\Support;

use InvalidArgumentException;

/**
 * Konversi kuantitas satuan input -> satuan dasar dengan pembulatan HALF-UP ke 4 desimal
 * (sama dengan kolom decimal(18,4)). Murni (tanpa database), dipakai module mana pun.
 *
 * Melempar InvalidArgumentException; pemanggil mengubahnya menjadi error yang sesuai konteksnya
 * (mis. ValidationException berkunci field).
 *
 * Catatan: module Purchase masih memiliki salinan sendiri (Purchase\Support\Quantity) yang
 * melempar exception Purchase. Konsolidasi ke class ini dapat dilakukan belakangan.
 */
final class Quantity
{
    public static function toBase(string $unitQuantity, string $conversionRate): string
    {
        foreach ([$unitQuantity, $conversionRate] as $value) {
            if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
                throw new InvalidArgumentException('Kuantitas atau konversi satuan bukan angka yang valid.');
            }
        }

        // bcadd menghitung eksak lalu memotong ke 4 desimal: menambah 0.00005 = pembulatan half-up.
        $base = bcadd(bcmul($unitQuantity, $conversionRate, 10), '0.00005', 4);

        if (bccomp($base, '0', 4) <= 0) {
            throw new InvalidArgumentException('Jumlah setelah konversi ke satuan dasar tidak boleh nol.');
        }

        return $base;
    }
}