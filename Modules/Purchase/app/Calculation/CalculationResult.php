<?php

namespace Modules\Purchase\Calculation;

/**
 * Hasil hitungan satu dokumen.
 *
 * Identitas yang SELALU berlaku (diuji di unit test):
 *   grandTotal = itemsSubtotal - discountAmount + taxAmount
 *   grandTotal = SUM(lines.lineTotal)
 *
 * $lines berurutan SAMA dengan urutan input (index ke index), sehingga service dapat
 * memetakan hasil ke item dokumen tanpa pencocokan ulang.
 */
final readonly class CalculationResult
{
    /**
     * @param list<CalculatedLine> $lines
     */
    public function __construct(
        public array $lines,
        public string $itemsSubtotal,
        public string $discountAmount,
        public string $taxAmount,
        public string $grandTotal,
    ) {
    }

    /**
     * Kolom total pada header PO / GR (siap dipakai fill()).
     *
     * @return array<string, string>
     */
    public function toHeaderAmounts(): array
    {
        return [
            'items_subtotal' => $this->itemsSubtotal,
            'discount_amount' => $this->discountAmount,
            'tax_amount' => $this->taxAmount,
            'grand_total' => $this->grandTotal,
        ];
    }
}