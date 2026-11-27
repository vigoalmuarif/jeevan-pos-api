<?php

namespace Modules\Purchase\Support;

/**
 * Kebijakan pembelian milik merchant (hasil baca Setting).
 *
 * @param string $defaultTaxRate    persen; dipakai bila produk tidak punya tarif sendiri
 * @param bool   $taxInCost         true = pajak ikut HPP (non-PKP); false = PPN masukan dikreditkan
 * @param bool   $pricesIncludeTax  default harga satuan inklusif pajak untuk dokumen baru
 * @param bool   $requestEnabled    true = fitur Purchase Request aktif untuk merchant ini
 * @param bool   $separateApprover  true = penyetuju PR/PO tidak boleh pembuat dokumennya
 * @param bool   $requirePo         true = penerimaan barang wajib merujuk ke PO
 */
final readonly class PurchaseSettings
{
    public function __construct(
        public string $defaultTaxRate,
        public bool $taxInCost,
        public bool $pricesIncludeTax,
        public bool $requestEnabled = false,
        public bool $separateApprover = true,
        public bool $requirePo = false,
    ) {
    }
}