<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantUnit;
use Modules\Product\Models\ProductUnitConversion;

class ProductVariantUnitService
{
    public function create(int $variantId, array $data): ProductVariantUnit
    {
        $merchantId = $this->merchantId();
        $variant = ProductVariant::query()->where('merchant_id', $merchantId)->findOrFail($variantId);
        $product = Product::query()->where('merchant_id', $merchantId)->findOrFail($variant->product_id);
        $validUnit = DB::table('units')->where('id', $data['unit_id'])->where('is_active', true)->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('merchant_id')->orWhere('merchant_id', $merchantId))->exists();
        if (! $validUnit) {
            throw ValidationException::withMessages(['unit_id' => 'Satuan tidak aktif atau bukan milik merchant ini.']);
        }

        return DB::transaction(function () use ($merchantId, $variant, $product, $data) {
            $product = Product::query()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($product->id);
            $conversion = $product->unitConversions()->where('unit_id', $data['unit_id'])->first();
            if ($conversion?->is_base) {
                throw ValidationException::withMessages(['unit_id' => 'Satuan dasar sudah terdaftar pada produk.']);
            }

            if ($conversion) {
                throw ValidationException::withMessages(['unit_id' => 'Satuan tersebut sudah terdaftar pada produk ini.']);
            }

            $conversion = $product->unitConversions()->create([
                'merchant_id' => $merchantId,
                'unit_id' => $data['unit_id'],
                'conversion_value' => $data['conversion_value'],
                'is_base' => false,
            ]);
            $targetUnit = null;
            foreach ($product->variants()->get() as $productVariant) {
                $existing = ProductVariantUnit::withTrashed()
                    ->where('merchant_id', $merchantId)
                    ->where('product_variant_id', $productVariant->id)
                    ->where('unit_id', $conversion->unit_id)
                    ->first();
                if ($existing && ! $existing->trashed()) {
                    throw ValidationException::withMessages(['unit_id' => 'Satuan ini sudah terdapat pada salah satu varian produk.']);
                }

                $attributes = [
                    'merchant_id' => $merchantId,
                    'conversion_value' => $conversion->conversion_value,
                    'is_base' => false,
                    'is_active' => true,
                    'barcode' => $productVariant->id === $variant->id ? ($data['barcode'] ?? null) : ($existing?->barcode),
                    'cost' => $productVariant->id === $variant->id ? ($data['cost'] ?? 0) : ($existing?->cost ?? 0),
                    'default_price' => $productVariant->id === $variant->id ? ($data['default_price'] ?? 0) : ($existing?->default_price ?? 0),
                ];

                if ($existing) {
                    $existing->fill($attributes);
                    $existing->restore();
                    $existing->save();
                    $unit = $existing->refresh();
                } else {
                    $unit = $productVariant->units()->create([
                        ...$attributes,
                        'unit_id' => $conversion->unit_id,
                    ]);
                }

                if ($productVariant->id === $variant->id) {
                    $targetUnit = $unit;
                }
            }

            return $targetUnit ?? throw new \LogicException('Varian tujuan tidak ditemukan pada produk.');
        });
    }

    public function update(int $id, array $data): ProductVariantUnit
    {
        $merchantId = $this->merchantId();

        return DB::transaction(function () use ($id, $data, $merchantId) {
            $unit = ProductVariantUnit::query()->where('merchant_id', $merchantId)->findOrFail($id);
            $variant = ProductVariant::query()->where('merchant_id', $merchantId)->findOrFail($unit->product_variant_id);
            $product = Product::query()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($variant->product_id);
            $conversion = $product->unitConversions()->where('unit_id', $unit->unit_id)->firstOrFail();

            if ($conversion->is_base && isset($data['conversion_value']) && (float) $data['conversion_value'] !== 1.0) {
                throw ValidationException::withMessages(['conversion_value' => 'Konversi satuan dasar harus tetap 1.']);
            }

            if (array_key_exists('conversion_value', $data)) {
                $conversion->conversion_value = $data['conversion_value'];
                $conversion->save();
                ProductVariantUnit::query()
                    ->where('merchant_id', $merchantId)
                    ->whereIn('product_variant_id', $product->variants()->pluck('id'))
                    ->where('unit_id', $unit->unit_id)
                    ->update(['conversion_value' => $data['conversion_value']]);
            }

            $unit->fill(array_intersect_key($data, array_flip(['barcode', 'cost', 'default_price', 'is_active'])))->save();

            return $unit->refresh();
        });
    }

    public function delete(int $id): void
    {
        $merchantId = $this->merchantId();
        DB::transaction(function () use ($id, $merchantId) {
            $unit = ProductVariantUnit::query()->where('merchant_id', $merchantId)->findOrFail($id);
            $variant = ProductVariant::query()->where('merchant_id', $merchantId)->findOrFail($unit->product_variant_id);
            $product = Product::query()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($variant->product_id);
            $conversion = $product->unitConversions()->where('unit_id', $unit->unit_id)->firstOrFail();
            if ($conversion->is_base) {
                throw ValidationException::withMessages(['unit' => 'Satuan dasar tidak dapat dihapus.']);
            }

            $conversion->delete();
            ProductVariantUnit::query()
                ->where('merchant_id', $merchantId)
                ->whereIn('product_variant_id', $product->variants()->pluck('id'))
                ->where('unit_id', $unit->unit_id)
                ->delete();
        });
    }

    private function merchantId(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');
        return (int) MerchantContext::id();
    }
}
