<?php

namespace Modules\Purchase\Calculation;

/**
 * Hasil hitungan satu baris. Semua nilai uang DINORMALISASI EKSKLUSIF pajak
 * (sama dengan kolom di tabel item), dengan 4 angka desimal.
 *
 *   subtotal   = grossAmount - discountAmount - allocatedDiscountAmount
 *   lineTotal  = subtotal + taxAmount
 *   costAmount = subtotal (+ taxAmount bila tax_in_cost)   -> dasar HPP
 *   unitCost   = costAmount / baseQuantity                  -> HPP per SATUAN DASAR
 */
final readonly class CalculatedLine
{
    public function __construct(
        public string $baseQuantity,
        public string $grossAmount,
        public string $discountAmount,
        public string $allocatedDiscountAmount,
        public string $taxAmount,
        public string $subtotal,
        public string $lineTotal,
        public string $costAmount,
        public string $unitCost,
    ) {
    }

    /**
     * Kolom item yang dimiliki PO maupun GR (siap dipakai fill()).
     * grossAmount tidak disimpan: ia hanya turunan (subtotal + diskon).
     *
     * @return array<string, string>
     */
    public function toItemAmounts(): array
    {
        return [
            'base_quantity' => $this->baseQuantity,
            'discount_amount' => $this->discountAmount,
            'allocated_discount_amount' => $this->allocatedDiscountAmount,
            'tax_amount' => $this->taxAmount,
            'subtotal' => $this->subtotal,
            'line_total' => $this->lineTotal,
        ];
    }

    /**
     * Kolom HPP, khusus goods_receipt_items (PO tidak memiliki kolom ini).
     *
     * @return array<string, string>
     */
    public function toCostAmounts(): array
    {
        return [
            'cost_amount' => $this->costAmount,
            'unit_cost' => $this->unitCost,
        ];
    }
}