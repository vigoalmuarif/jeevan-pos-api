<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Product\Models\ProductPrice;
use Modules\Product\Models\ProductVariantUnit;

class ProductPriceService
{
    public function list(int $variantUnitId)
    {
        $variantUnit = $this->variantUnit($variantUnitId);
        return $variantUnit->prices()->where('merchant_id', $this->merchantId())->orderBy('branch_id')->orderBy('price_list_id')->orderBy('min_qty')->get();
    }

    public function create(int $variantUnitId, array $data): ProductPrice
    {
        $variantUnit = $this->variantUnit($variantUnitId);
        $data = $this->normalize($data);
        $this->assertScopedReferences($data);
        $this->assertUniqueRule($variantUnitId, $data);
        return $variantUnit->prices()->create([
            ...$data,
            'merchant_id' => $this->merchantId(),
            'created_by' => auth()->guard('merchant')->id(),
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(int $variantUnitId, int $priceId, array $data): ProductPrice
    {
        $this->variantUnit($variantUnitId);
        $price = ProductPrice::query()->where('merchant_id', $this->merchantId())
            ->where('product_variant_unit_id', $variantUnitId)->findOrFail($priceId);
        $data = $this->normalize($data, false);
        $this->assertScopedReferences($data);
        $this->assertUniqueRule($variantUnitId, [...$price->only(['branch_id', 'price_list_id', 'min_qty']), ...$data], $priceId);
        $price->fill($data)->save();
        return $price->refresh();
    }

    public function delete(int $variantUnitId, int $priceId): void
    {
        $this->variantUnit($variantUnitId);
        ProductPrice::query()->where('merchant_id', $this->merchantId())
            ->where('product_variant_unit_id', $variantUnitId)->findOrFail($priceId)->delete();
    }

    private function normalize(array $data, bool $create = true): array
    {
        if ($create) {
            $data['min_qty'] ??= 1;
            $data['pricing_mode'] ??= 'fixed';
        }
        if (($data['pricing_mode'] ?? null) === 'margin' && isset($data['price']) && (float) $data['price'] > 10000) {
            throw ValidationException::withMessages(['price' => 'Nilai markup persen terlalu besar.']);
        }
        return $data;
    }

    private function assertUniqueRule(int $variantUnitId, array $data, ?int $ignoreId = null): void
    {
        $query = ProductPrice::withoutGlobalScopes()->where('merchant_id', $this->merchantId())
            ->where('product_variant_unit_id', $variantUnitId)
            ->where('branch_id', $data['branch_id'] ?? null)
            ->where('price_list_id', $data['price_list_id'] ?? null)
            ->where('min_qty', $data['min_qty'] ?? 1)
            ->where('is_active', true)->whereNull('deleted_at');
        if ($ignoreId) $query->where('id', '<>', $ignoreId);
        if ($query->exists() && ($data['is_active'] ?? true)) {
            throw ValidationException::withMessages(['min_qty' => 'Rule harga aktif dengan cabang, daftar harga, dan jumlah minimum tersebut sudah ada.']);
        }
    }

    private function assertScopedReferences(array $data): void
    {
        $merchantId = $this->merchantId();
        if (! empty($data['price_list_id']) && ! DB::table('price_lists')->where('id', $data['price_list_id'])->where('merchant_id', $merchantId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['price_list_id' => 'Daftar harga tidak aktif atau bukan milik merchant ini.']);
        }
        if (! empty($data['branch_id']) && ! DB::table('branches')->where('id', $data['branch_id'])->where('merchant_id', $merchantId)->where('is_active', true)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'Cabang tidak aktif atau bukan milik merchant ini.']);
        }
    }

    private function variantUnit(int $id): ProductVariantUnit
    {
        return ProductVariantUnit::query()->where('merchant_id', $this->merchantId())->findOrFail($id);
    }

    private function merchantId(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');
        return (int) MerchantContext::id();
    }
}
