<?php

namespace Modules\Purchase\Support;

use Modules\Purchase\Exceptions\PurchaseRuleException;

/**
 * Menghitung total faktur dari penerimaan barang (goods receipt) yang ditagih.
 *
 *   grand_total = items_subtotal - discount_amount + tax_amount + rounding_amount
 *
 * Total tiap penerimaan SUDAH final (hasil PurchaseCalculator saat GR dibuat), jadi faktur
 * hanya menjumlahkannya; tidak ada perhitungan diskon/pajak ulang di sini. rounding_amount
 * (bertanda) menampung selisih pembulatan dengan tagihan supplier.
 *
 * Murni (tanpa database) agar dapat diuji. Menerima model atau array.
 */
final class PurchaseInvoiceTotals
{
    /**
     * @param  iterable<array<string,mixed>|object> $receipts
     * @return array{items_subtotal:string,discount_amount:string,tax_amount:string,rounding_amount:string,grand_total:string}
     */
    public static function fromReceipts(iterable $receipts, string $rounding = '0'): array
    {
        // bcmath menolak format lain, dan lebih dari 4 desimal akan terpotong DIAM-DIAM; tolak lebih awal.
        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $rounding)) {
            throw new PurchaseRuleException('Nilai pembulatan tidak valid (angka dengan maksimal 4 desimal).');
        }

        $subtotal = $discount = $tax = '0.0000';
        $count = 0;
        foreach ($receipts as $receipt) {
            $subtotal = bcadd($subtotal, self::field($receipt, 'items_subtotal'), 4);
            $discount = bcadd($discount, self::field($receipt, 'discount_amount'), 4);
            $tax = bcadd($tax, self::field($receipt, 'tax_amount'), 4);
            $count++;
        }

        if ($count === 0) {
            throw new PurchaseRuleException('Faktur harus menagih minimal satu penerimaan barang.');
        }

        $rounding = bcadd($rounding, '0', 4);
        $grand = bcadd(bcadd(bcsub($subtotal, $discount, 4), $tax, 4), $rounding, 4);

        // Pembulatan hanya untuk selisih kecil; total negatif berarti input pembulatan salah.
        if (bccomp($grand, '0', 4) < 0) {
            throw new PurchaseRuleException('Pembulatan membuat total faktur bernilai negatif.');
        }

        return [
            'items_subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'rounding_amount' => $rounding,
            'grand_total' => $grand,
        ];
    }

    /** Ambil kolom dari model atau array sebagai string (tanpa bergantung pada helper framework). */
    private static function field(array|object $row, string $key): string
    {
        return (string) (is_array($row) ? ($row[$key] ?? '0') : ($row->{$key} ?? '0'));
    }
}