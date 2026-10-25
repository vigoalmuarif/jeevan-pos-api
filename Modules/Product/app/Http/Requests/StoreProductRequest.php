<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Core\Abstracts\BaseFormRequest;

class StoreProductRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';

    public function rules(): array
    {
        $merchantId = $this->merchantId();
        $unitExists = fn ($query) => $query->whereNull('merchant_id')->orWhere('merchant_id', $merchantId);
        $catalogExists = fn ($query) => $query->whereNull('merchant_id')->orWhere('merchant_id', $merchantId);

        return [
            'type' => ['sometimes', Rule::in(['product', 'service'])],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where($catalogExists)],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where($catalogExists)],
            'supplier_ids' => ['sometimes', 'array'],
            'supplier_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('suppliers', 'id')
                    ->where('merchant_id', $merchantId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'track_stock' => ['sometimes', 'boolean'],
            'is_taxable' => ['sometimes', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            // Simple product: the backend creates one default variant.
            'sku' => ['required_without:variants', 'nullable', 'string', 'max:255', Rule::unique('product_variants', 'sku')->where('merchant_id', $merchantId)],
            'base_unit_id' => ['required_without:variants', 'nullable', 'integer', Rule::exists('units', 'id')->where($unitExists)],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variant_units', 'barcode')->where('merchant_id', $merchantId)->whereNull('deleted_at')],
            'cost' => ['sometimes', 'numeric', 'min:0'],
            'default_price' => ['sometimes', 'numeric', 'min:0'],

            // Multi-variant product.
            'variants' => ['sometimes', 'array', 'min:1'],
            'variants.*.sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')->where('merchant_id', $merchantId)],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.is_default' => ['sometimes', 'boolean'],
            'variants.*.base_unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where($unitExists)],
            'variants.*.barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variant_units', 'barcode')->where('merchant_id', $merchantId)->whereNull('deleted_at')],
            'variants.*.cost' => ['sometimes', 'numeric', 'min:0'],
            'variants.*.default_price' => ['sometimes', 'numeric', 'min:0'],
            'variants.*.additional_units' => ['sometimes', 'array'],
            'variants.*.additional_units.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where($unitExists)],
            'variants.*.additional_units.*.conversion_value' => ['required', 'numeric', 'gt:0'],
            'variants.*.additional_units.*.barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variant_units', 'barcode')->where('merchant_id', $merchantId)->whereNull('deleted_at')],
            'variants.*.additional_units.*.cost' => ['sometimes', 'numeric', 'min:0'],
            'variants.*.additional_units.*.default_price' => ['sometimes', 'numeric', 'min:0'],
            'variants.*.images' => ['sometimes', 'array', 'max:5'],
            'variants.*.images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('is_taxable') && $this->input('tax_rate') === null) {
                $validator->errors()->add('tax_rate', 'Tarif pajak wajib diisi untuk produk kena pajak.');
            }
            if ($this->input('type') === 'service' && $this->boolean('track_stock')) {
                $validator->errors()->add('track_stock', 'Produk jasa tidak dapat mengelola stok.');
            }
            if ($this->input('type') === 'service' && ! empty($this->input('supplier_ids'))) {
                $validator->errors()->add('supplier_ids', 'Supplier hanya dapat dihubungkan dengan produk fisik.');
            }
            $variants = $this->input('variants');
            if (! is_array($variants)) {
                return;
            }

            $duplicateSkus = collect($variants)->pluck('sku')->filter()->duplicates();
            if ($duplicateSkus->isNotEmpty()) {
                $validator->errors()->add('variants', 'SKU varian dalam satu produk tidak boleh duplikat.');
            }
            $barcodes = collect([$this->input('barcode')])
                ->merge(collect($variants)->pluck('barcode'))
                ->merge(collect($variants)->flatMap(fn ($variant) => collect($variant['additional_units'] ?? [])->pluck('barcode')))
                ->filter();
            if ($barcodes->duplicates()->isNotEmpty()) {
                $validator->errors()->add('variants', 'Barcode dalam satu produk tidak boleh duplikat.');
            }

            $defaults = collect($variants)->where('is_default', true)->count();
            if ($defaults > 1) {
                $validator->errors()->add('variants', 'Hanya satu varian yang boleh menjadi default.');
            }

            foreach ($variants as $index => $variant) {
                $unitIds = collect($variant['additional_units'] ?? [])->pluck('unit_id');
                if ($unitIds->contains((int) ($variant['base_unit_id'] ?? 0))) {
                    $validator->errors()->add("variants.{$index}.additional_units", 'Satuan dasar tidak boleh diulang sebagai satuan tambahan.');
                }
                if ($unitIds->duplicates()->isNotEmpty()) {
                    $validator->errors()->add("variants.{$index}.additional_units", 'Satuan tambahan tidak boleh duplikat.');
                }
            }
        });
    }
}
