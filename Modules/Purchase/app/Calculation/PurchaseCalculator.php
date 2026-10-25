<?php

namespace Modules\Purchase\Calculation;

use Modules\Purchase\Enums\DiscountType;
use Modules\Purchase\Exceptions\PurchaseCalculationException;

/**
 * Satu-satunya tempat perhitungan diskon, pajak, dan HPP dokumen pembelian.
 * Dipakai PurchaseOrderService dan GoodsReceiptService.
 *
 * Class ini MURNI: tanpa database, tanpa Eloquent, tanpa state. Input dan output berupa
 * DTO bertipe string. Dengan begitu mudah diuji, dan bug hitungan uang (yang berdampak
 * langsung ke HPP) terisolasi di satu file.
 *
 * ALUR HITUNG
 *  1. Per baris : gross = qty x harga, lalu diskon item  -> "afterItem".
 *  2. Header    : diskon header dihitung dari SUM(afterItem), lalu DIALOKASIKAN
 *                 proporsional ke tiap baris (supaya pajak & HPP per baris akurat).
 *  3. Per baris : net = afterItem - alokasi, lalu pajak:
 *                   - eksklusif : subtotal = net,            pajak = subtotal x tarif
 *                   - inklusif  : subtotal = net / (1+tarif), pajak = net - subtotal
 *                 Semua nilai yang dikembalikan DINORMALISASI EKSKLUSIF pajak.
 *  4. HPP       : costAmount = subtotal (+ pajak bila taxInCost), unitCost per satuan dasar.
 *
 * KEBIJAKAN PEMBULATAN
 *  - Semua angka memakai bcmath (bukan float). Hitungan antara memakai 10 desimal,
 *    hasil akhir dibulatkan HALF-UP ke 4 desimal (sama dengan decimal(18,4) di database).
 *  - Pembulatan terjadi pada titik yang tetap dan terdokumentasi, sehingga hasilnya
 *    deterministik: input yang sama selalu menghasilkan angka yang sama persis.
 */
final class PurchaseCalculator
{
    /** Jumlah desimal hasil akhir; HARUS sama dengan skala kolom uang di database. */
    private const SCALE = 4;

    /** Presisi hitungan antara agar pembulatan hanya terjadi pada titik yang ditentukan. */
    private const WORK_SCALE = 10;

    /**
     * @param list<CalculationLine> $lines
     *
     * @throws PurchaseCalculationException bila input tidak valid
     */
    public function calculate(CalculationHeader $header, array $lines): CalculationResult
    {
        if ($lines === []) {
            throw new PurchaseCalculationException('Dokumen harus memiliki minimal satu baris item.');
        }

        $lines = array_values($lines); // jamin index 0..n-1 agar sejajar dengan hasil

        // ---- Tahap 1: gross dan diskon item per baris ---------------------------------
        $prepared = [];
        foreach ($lines as $i => $line) {
            $label = 'Baris #'.($i + 1);
            $this->assertLine($line, $label);

            $gross = $this->round(bcmul($line->unitQuantity, $line->unitPrice, self::WORK_SCALE));
            $itemDiscount = $this->discount($line->discountType, $line->discountValue, $gross, $label);

            $prepared[] = [
                'gross' => $gross,
                'itemDiscount' => $itemDiscount,
                'afterItem' => bcsub($gross, $itemDiscount, self::SCALE),
            ];
        }

        // ---- Tahap 2: diskon header dan alokasinya ke baris ---------------------------
        $basis = '0.0000';
        foreach ($prepared as $p) {
            $basis = bcadd($basis, $p['afterItem'], self::SCALE);
        }

        $headerDiscount = $this->discount($header->discountType, $header->discountValue, $basis, 'Header');
        $allocations = $this->allocate($headerDiscount, array_column($prepared, 'afterItem'), $basis);

        // ---- Tahap 3 & 4: pajak, normalisasi eksklusif, dan HPP per baris --------------
        $calculated = [];
        $itemsSubtotal = $discountTotal = $taxTotal = $grandTotal = '0.0000';

        foreach ($lines as $i => $line) {
            $p = $prepared[$i];
            $alloc = $allocations[$i];

            // Dasar harga setelah semua diskon. Bila harga INKLUSIF pajak, nilai ini
            // sudah memuat pajak (inilah yang dibayar ke supplier).
            $net = bcsub($p['afterItem'], $alloc, self::SCALE);

            if (! $header->isTaxInclusive) {
                $subtotal = $net;
                $tax = $this->round(bcdiv(bcmul($subtotal, $line->taxRate, self::WORK_SCALE), '100', self::WORK_SCALE));
                $lineTotal = bcadd($subtotal, $tax, self::SCALE);

                $grossEx = $p['gross'];
                $itemDiscountEx = $p['itemDiscount'];
                $allocEx = $alloc;
            } else {
                // Pajak "diekstrak" dari harga: net = subtotal x (1 + tarif).
                $divisor = bcadd('1', bcdiv($line->taxRate, '100', self::WORK_SCALE), self::WORK_SCALE);

                $lineTotal = $net;
                $subtotal = $this->round(bcdiv($net, $divisor, self::WORK_SCALE));
                // Pajak = selisih, bukan dihitung terpisah, agar subtotal + pajak == lineTotal TEPAT.
                $tax = bcsub($lineTotal, $subtotal, self::SCALE);

                // Diskon juga dikonversi ke basis eksklusif supaya identitas
                // subtotal = gross - diskon - alokasi tetap berlaku pada data yang tersimpan.
                $itemDiscountEx = $this->round(bcdiv($p['itemDiscount'], $divisor, self::WORK_SCALE));
                $allocEx = $this->round(bcdiv($alloc, $divisor, self::WORK_SCALE));
                // gross eksklusif = penyeimbang; menyerap selisih pembulatan <= 0.0002.
                $grossEx = bcadd(bcadd($subtotal, $itemDiscountEx, self::SCALE), $allocEx, self::SCALE);
            }

            $baseQty = $this->round(bcmul($line->unitQuantity, $line->conversionRate, self::WORK_SCALE));
            if (bccomp($baseQty, '0', self::SCALE) <= 0) {
                throw new PurchaseCalculationException("{$this->label($i)}: kuantitas satuan dasar bernilai nol setelah konversi.");
            }

            // HPP: pajak hanya ikut biaya bila merchant tidak dapat mengkreditkan PPN masukan.
            $costAmount = $header->taxInCost ? bcadd($subtotal, $tax, self::SCALE) : $subtotal;
            // HPP per SATUAN DASAR inilah yang dikirim ke StockService (moving average).
            // Dibatasi 4 desimal (skala kolom), sehingga unitCost x qty dapat berselisih
            // sangat kecil dari costAmount; costAmount tetap nilai acuan dokumen.
            $unitCost = $this->round(bcdiv($costAmount, $baseQty, self::WORK_SCALE));

            $calculated[] = new CalculatedLine(
                baseQuantity: $baseQty,
                grossAmount: $grossEx,
                discountAmount: $itemDiscountEx,
                allocatedDiscountAmount: $allocEx,
                taxAmount: $tax,
                subtotal: $subtotal,
                lineTotal: $lineTotal,
                costAmount: $costAmount,
                unitCost: $unitCost,
            );

            // Total header (eksklusif): itemsSubtotal = SUM(subtotal + alokasi) sehingga
            // grandTotal = itemsSubtotal - discountTotal + taxTotal selalu sama dengan SUM(lineTotal).
            $itemsSubtotal = bcadd($itemsSubtotal, bcadd($subtotal, $allocEx, self::SCALE), self::SCALE);
            $discountTotal = bcadd($discountTotal, $allocEx, self::SCALE);
            $taxTotal = bcadd($taxTotal, $tax, self::SCALE);
            $grandTotal = bcadd($grandTotal, $lineTotal, self::SCALE);
        }

        return new CalculationResult($calculated, $itemsSubtotal, $discountTotal, $taxTotal, $grandTotal);
    }

    /**
     * Hitung nominal diskon dari jenis + nilai terhadap sebuah dasar.
     * Input yang mustahil (negatif, persen > 100, nominal > dasar) DITOLAK, tidak dipotong diam-diam.
     */
    private function discount(?DiscountType $type, string $value, string $base, string $label): string
    {
        $value = $this->number($value, "{$label}: nilai diskon");

        if ($type === null) {
            // Nilai tanpa jenis adalah input ambigu; jangan ditebak.
            if (bccomp($value, '0', self::WORK_SCALE) !== 0) {
                throw new PurchaseCalculationException("{$label}: jenis diskon wajib diisi bila nilai diskon > 0.");
            }

            return '0.0000';
        }

        if (bccomp($value, '0', self::WORK_SCALE) < 0) {
            throw new PurchaseCalculationException("{$label}: nilai diskon tidak boleh negatif.");
        }

        if ($type === DiscountType::Percent) {
            if (bccomp($value, '100', self::WORK_SCALE) > 0) {
                throw new PurchaseCalculationException("{$label}: diskon persen tidak boleh melebihi 100.");
            }

            return $this->round(bcdiv(bcmul($base, $value, self::WORK_SCALE), '100', self::WORK_SCALE));
        }

        // DiscountType::Amount
        $amount = $this->round($value);
        if (bccomp($amount, $base, self::SCALE) > 0) {
            throw new PurchaseCalculationException("{$label}: diskon nominal melebihi nilai yang didiskon.");
        }

        return $amount;
    }

    /**
     * Alokasikan diskon header ke baris secara PROPORSIONAL terhadap nilai baris
     * (setelah diskon item). Jumlah alokasi dijamin SAMA PERSIS dengan total diskon.
     *
     * Selisih pembulatan ditampung baris dengan nilai TERBESAR: selisihnya (maks 0.00005
     * per baris) kecil dibanding bagiannya sendiri, jadi alokasi tidak pernah menjadi negatif.
     *
     * @param  list<string> $bases nilai tiap baris setelah diskon item
     * @return list<string>
     */
    private function allocate(string $total, array $bases, string $basisSum): array
    {
        $result = array_fill(0, count($bases), '0.0000');

        if (bccomp($total, '0', self::SCALE) === 0 || bccomp($basisSum, '0', self::SCALE) === 0) {
            return $result;
        }

        // Cari baris penampung sisa (nilai terbesar; seri -> baris pertama).
        $holder = 0;
        foreach ($bases as $i => $base) {
            if (bccomp($base, $bases[$holder], self::SCALE) > 0) {
                $holder = $i;
            }
        }

        $allocated = '0.0000';
        foreach ($bases as $i => $base) {
            if ($i === $holder || bccomp($base, '0', self::SCALE) <= 0) {
                continue;
            }

            $share = $this->round(bcdiv(bcmul($total, $base, self::WORK_SCALE), $basisSum, self::WORK_SCALE));
            $result[$i] = $share;
            $allocated = bcadd($allocated, $share, self::SCALE);
        }

        $result[$holder] = bcsub($total, $allocated, self::SCALE);

        return $result;
    }

    /**
     * Pembulatan HALF-UP (menjauhi nol) ke SCALE desimal.
     * bcadd/bcsub menghitung eksak lalu memotong ke SCALE, jadi menambah setengah satuan
     * terkecil (0.00005) sebelum dipotong sama dengan pembulatan standar.
     */
    private function round(string $value): string
    {
        $half = '0.00005';

        return bccomp($value, '0', self::WORK_SCALE) >= 0
            ? bcadd($value, $half, self::SCALE)
            : bcsub($value, $half, self::SCALE);
    }

    /** Validasi satu baris input. */
    private function assertLine(CalculationLine $line, string $label): void
    {
        $qty = $this->number($line->unitQuantity, "{$label}: kuantitas");
        $conv = $this->number($line->conversionRate, "{$label}: konversi satuan");
        $price = $this->number($line->unitPrice, "{$label}: harga satuan");
        $tax = $this->number($line->taxRate, "{$label}: tarif pajak");

        if (bccomp($qty, '0', self::WORK_SCALE) <= 0) {
            throw new PurchaseCalculationException("{$label}: kuantitas harus lebih besar dari 0.");
        }
        if (bccomp($conv, '0', self::WORK_SCALE) <= 0) {
            throw new PurchaseCalculationException("{$label}: konversi satuan harus lebih besar dari 0.");
        }
        if (bccomp($price, '0', self::WORK_SCALE) < 0) {
            throw new PurchaseCalculationException("{$label}: harga satuan tidak boleh negatif.");
        }
        if (bccomp($tax, '0', self::WORK_SCALE) < 0 || bccomp($tax, '100', self::WORK_SCALE) > 0) {
            throw new PurchaseCalculationException("{$label}: tarif pajak harus antara 0 dan 100.");
        }
    }

    /**
     * bcmath hanya menerima string numerik polos (tanpa notasi ilmiah seperti "1e3");
     * input lain akan menimbulkan error yang sulit dilacak, jadi ditolak lebih awal.
     */
    private function number(string $value, string $field): string
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new PurchaseCalculationException("{$field} bukan angka yang valid.");
        }

        return $value;
    }

    private function label(int $index): string
    {
        return 'Baris #'.($index + 1);
    }
}