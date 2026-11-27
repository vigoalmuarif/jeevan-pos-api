<?php

namespace Modules\Purchase\Support;

use Modules\Purchase\Exceptions\PurchaseRuleException;

/**
 * Konversi kuantitas satuan input -> satuan dasar dengan pembulatan HALF-UP ke 4 desimal
 * (sama dengan kolom decimal(18,4) dan dengan PurchaseCalculator).
 * Murni (tanpa database) agar dapat diuji.
 */
final class Quantity
{
    public static function toBase(string $unitQuantity, string $conversionRate): string
    {
        foreach ([$unitQuantity, $conversionRate] as $value) {
            if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
                throw new PurchaseRuleException('Kuantitas atau konversi satuan bukan angka yang valid.');
            }
        }

        // bcadd menghitung eksak lalu memotong ke 4 desimal: menambah 0.00005 = pembulatan half-up.
        $base = bcadd(bcmul($unitQuantity, $conversionRate, 10), '0.00005', 4);

        if (bccomp($base, '0', 4) <= 0) {
            throw new PurchaseRuleException('Jumlah setelah konversi ke satuan dasar tidak boleh nol.');
        }

        return $base;
    }
}