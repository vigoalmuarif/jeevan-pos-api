<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Support\Numeric;
use Modules\Purchase\Support\PurchaseSettings;

/**
 * Titik TUNGGAL pembacaan setting merchant untuk module Purchase.
 *
 * !! BELUM TERSAMBUNG ke module Setting !!
 * value() saat ini selalu mengembalikan null sehingga SEMUA setting memakai DEFAULTS di bawah
 * (PPN 11%, pajak tidak ikut HPP, harga eksklusif pajak). Sambungkan value() ke API module
 * Setting Anda; tidak ada kode lain yang perlu diubah.
 *
 * Tidak ada cache di properti: service ini hidup lintas request pada Octane, dan cache
 * di sini akan bocor antar merchant. Cache menjadi tanggung jawab module Setting.
 */
class PurchaseSettingsResolver
{
    /** Key setting => nilai default bila merchant belum mengatur. */
    private const DEFAULTS = [
        'purchase.default_tax_rate' => '11',
        'purchase.tax_in_cost' => false,
        'purchase.prices_include_tax' => false,
    ];

    public function get(): PurchaseSettings
    {
        return new PurchaseSettings(
            defaultTaxRate: Numeric::string($this->read('purchase.default_tax_rate'), self::DEFAULTS['purchase.default_tax_rate']),
            taxInCost: (bool) $this->read('purchase.tax_in_cost'),
            pricesIncludeTax: (bool) $this->read('purchase.prices_include_tax'),
        );
    }

    private function read(string $key): mixed
    {
        return $this->value($key) ?? self::DEFAULTS[$key];
    }

    /**
     * TODO(integrasi Setting): kembalikan nilai setting merchant aktif, atau null bila belum diatur.
     * Contoh: return app(\Modules\Setting\Services\SettingService::class)->get($key);
     */
    protected function value(string $key): mixed
    {
        return null;
    }
}
