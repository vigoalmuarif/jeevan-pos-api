<?php

namespace Modules\Purchase\Services;

use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Exceptions\PurchaseRuleException;
use Modules\Purchase\Support\Numeric;
use Illuminate\Support\Facades\DB;

/**
 * Melengkapi baris input dengan data yang TIDAK boleh dipercaya dari client:
 *  - conversion_rate : diambil dari product_unit_conversions (satuan produk -> satuan dasar)
 *  - tax_rate        : tarif final = input baris -> tarif produk -> default merchant
 *
 * Satu-satunya titik kopling ke skema Product, jadi bila nama kolom berbeda cukup
 * disesuaikan di sini. MerchantScope (fail-closed) pada model membuat varian milik merchant
 * lain tidak ditemukan, sehingga id lintas-tenant otomatis ditolak.
 */
class PurchaseLineResolver
{
    /** Nama kolom konversi pada product_unit_conversions. SESUAIKAN bila berbeda. */
    protected const CONVERSION_COLUMN = 'conversion_value';

    /**
     * @param  list<array<string,mixed>> $lines
     * @return list<array<string,mixed>> baris yang sama + conversion_rate + tax_rate final
     */
    public function resolve(array $lines, string $defaultTaxRate): array
    {
        $variantIds = array_values(array_unique(array_map(
            static fn(array $line): int => (int) $line['product_variant_id'],
            $lines
        )));

        // Dua query untuk SELURUH dokumen (bukan satu per baris) agar bebas N+1.
        $variants = ProductVariant::query()
            ->whereIn('id', $variantIds)
            ->where('is_active', true)
            ->whereHas('product', fn($query) => $query
                ->where('is_active', true)
                ->where('type', 'product')
                ->where('track_stock', true))
            ->with('product:id,tax_rate')
            ->get(['id', 'product_id'])
            ->keyBy('id');

        $unitsByVariant = DB::table('product_variant_units as pvu')
            ->join('product_variants as pv', 'pv.id', '=', 'pvu.product_variant_id')
            ->join('product_unit_conversions as puc', function ($join) {
                $join->on('puc.product_id', '=', 'pv.product_id')
                    ->on('puc.unit_id', '=', 'pvu.unit_id');
            })
            ->join('units as u', 'u.id', '=', 'pvu.unit_id')
            ->whereIn('pvu.product_variant_id', $variantIds)
            ->whereColumn('pvu.merchant_id', 'pv.merchant_id')
            ->whereColumn('puc.merchant_id', 'pv.merchant_id')
            ->where('pvu.is_active', true)
            ->whereNull('pvu.deleted_at')
            ->where('pv.is_active', true)
            ->where('u.is_active', true)
            ->where(function ($query) {
                $query->whereNull('u.merchant_id')
                    ->orWhereColumn('u.merchant_id', 'pv.merchant_id');
            })
            ->whereNull('pv.deleted_at')
            ->whereNull('u.deleted_at')
            ->get(['pvu.product_variant_id', 'pvu.unit_id', 'puc.' . static::CONVERSION_COLUMN])
            ->groupBy('product_variant_id');

        $resolved = [];
        foreach ($lines as $i => $line) {
            $label = 'Baris #' . ($i + 1);
            $variantId = (int) $line['product_variant_id'];
            $unitId = (int) $line['unit_id'];

            $variant = $variants->get($variantId)
                ?? throw new PurchaseRuleException("{$label}: produk tidak ditemukan.");

            // Satuan wajib terdaftar pada varian tersebut; tidak boleh satuan sembarang.
            $unit = $unitsByVariant->get($variantId)?->firstWhere('unit_id', $unitId)
                ?? throw new PurchaseRuleException("{$label}: satuan tidak terdaftar pada produk ini.");

            // Tarif pajak: input baris (null/'' = "ikut default") -> tarif produk -> default merchant.
            // Nilai 0 adalah pilihan eksplisit "bebas pajak", bukan "kosong".
            $taxRate = $line['tax_rate'] ?? null;
            if ($taxRate === null || $taxRate === '') {
                $taxRate = $variant->product?->tax_rate ?? $defaultTaxRate;
            }

            $resolved[] = array_merge($line, [
                'conversion_rate' => Numeric::string($unit->{static::CONVERSION_COLUMN}),
                'tax_rate' => Numeric::string($taxRate),
            ]);
        }

        return $resolved;
    }
}
