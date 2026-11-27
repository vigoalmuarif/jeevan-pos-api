<?php

namespace Modules\Purchase\Services;

use Modules\Core\Helpers\MerchantContext;
use Modules\Purchase\Support\Numeric;
use Modules\Purchase\Support\PurchaseSettings;

/**
 * Titik TUNGGAL pembacaan setting merchant untuk module Purchase.
 *
 * Setting disimpan di metadata merchant (MerchantContext::get()->metadata, JSON bersarang),
 * sama seperti 'inventory.allow_negative_stock' pada StockService. Key (notasi titik):
 *   purchase.default_tax_rate   persen, default 11
 *   purchase.tax_in_cost        true = pajak ikut HPP (non-PKP), default false
 *   purchase.prices_include_tax true = harga satuan inklusif pajak, default false
 *   purchase.request_enabled    true = fitur Purchase Request aktif, default false
 *   purchase.separate_approver  true = penyetuju PR/PO tidak boleh pembuatnya, default true
 * Key yang belum diatur memakai DEFAULTS di bawah.
 *
 * Catatan: MerchantContext ber-cache (+- 1 jam). Perubahan setting baru terbaca setelah cache
 * merchant diperbarui; pastikan proses menyimpan setting membersihkan cache tersebut.
 *
 * Tidak ada cache di properti service ini: ia hidup lintas request (mis. Octane) dan cache di
 * sini akan bocor antar merchant.
 */
class PurchaseSettingsResolver
{
    /** Key setting => nilai default bila merchant belum mengatur. */
    private const DEFAULTS = [
        'purchase.default_tax_rate' => '11',
        'purchase.tax_in_cost' => false,
        'purchase.prices_include_tax' => false,
        'purchase.request_enabled' => false,
        'purchase.separate_approver' => true,
        'purchase.require_po' => false,
    ];

    public function get(): PurchaseSettings
    {
        return new PurchaseSettings(
            defaultTaxRate: Numeric::string($this->read('purchase.default_tax_rate'), self::DEFAULTS['purchase.default_tax_rate']),
            taxInCost: $this->bool($this->read('purchase.tax_in_cost')),
            pricesIncludeTax: $this->bool($this->read('purchase.prices_include_tax')),
            requestEnabled: $this->bool($this->read('purchase.request_enabled')),
            separateApprover: $this->bool($this->read('purchase.separate_approver')),
            requirePo: $this->bool($this->read('purchase.require_po')),
        );
    }

    private function read(string $key): mixed
    {
        return $this->value($key) ?? self::DEFAULTS[$key];
    }

    /** Nilai setting merchant aktif (notasi titik), atau null bila belum diatur. */
    protected function value(string $key): mixed
    {
        return data_get(MerchantContext::get()?->metadata ?? [], $key);
    }

    /** "false"/"0"/"off" dari JSON dibaca sebagai false (cast (bool) biasa menganggap "false" = true). */
    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}