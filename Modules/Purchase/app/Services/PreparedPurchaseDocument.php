<?php

namespace Modules\Purchase\Services;

/**
 * Hasil PurchaseDocumentPreparer: atribut header dan daftar item yang SIAP disimpan
 * (sudah berisi konversi satuan, tarif pajak final, hasil hitung diskon/pajak/HPP).
 */
final readonly class PreparedPurchaseDocument
{
    /**
     * @param array<string,mixed>       $header kolom header (diskon, flag pajak, total)
     * @param list<array<string,mixed>> $items  kolom per item, urutan sama dengan input
     */
    public function __construct(
        public array $header,
        public array $items,
    ) {
    }
}