<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Helpers\MerchantContext;
use Modules\Product\Models\ProductVariantUnit;

class PriceResolverService
{
    /**
     * Resolve a unit price by branch, price tier, and quantity.
     * For pricing_mode=margin, product_prices.price stores a markup percentage.
     */
    public function resolve(int $variantUnitId, float $quantity, ?int $priceListId = null, ?int $branchId = null): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');
        $unit = ProductVariantUnit::query()->where('merchant_id', $merchantId)->findOrFail($variantUnitId);
        if ($priceListId !== null && ! DB::table('price_lists')->where('id', $priceListId)->where('merchant_id', $merchantId)->where('is_active', true)->exists()) {
            abort(404, 'Daftar harga tidak ditemukan.');
        }
        if ($branchId !== null && ! DB::table('branches')->where('id', $branchId)->where('merchant_id', $merchantId)->where('is_active', true)->whereNull('deleted_at')->exists()) {
            abort(404, 'Cabang tidak ditemukan.');
        }

        $price = null;
        $scopes = [
            [$branchId, $priceListId],
            [$branchId, null],
            [null, $priceListId],
            [null, null],
        ];
        foreach ($scopes as [$scopeBranch, $scopeList]) {
            if ($scopeBranch === null && $scopeList === null) {
                break;
            }
            $query = DB::table('product_prices')->where('merchant_id', $merchantId)
                ->where('product_variant_unit_id', $unit->id)
                ->where('is_active', true)->whereNull('deleted_at')
                ->where('min_qty', '<=', $quantity);
            $scopeBranch === null ? $query->whereNull('branch_id') : $query->where('branch_id', $scopeBranch);
            $scopeList === null ? $query->whereNull('price_list_id') : $query->where('price_list_id', $scopeList);
            $price = $query->orderByDesc('min_qty')->orderByDesc('id')->first();
            if ($price) break;
        }

        if (! $price) {
            return ['price' => (float) $unit->default_price, 'pricing_mode' => 'default', 'source' => 'default_price', 'min_qty' => 1];
        }

        $amount = match ($price->pricing_mode) {
            'margin' => (float) $unit->cost * (1 + ((float) $price->price / 100)),
            'follow_center' => (float) $unit->default_price,
            default => (float) $price->price,
        };

        return [
            'price' => round($amount, 2),
            'pricing_mode' => $price->pricing_mode,
            'source' => 'product_price',
            'product_price_id' => $price->id,
            'price_list_id' => $price->price_list_id,
            'branch_id' => $price->branch_id,
            'min_qty' => (float) $price->min_qty,
        ];
    }
}
