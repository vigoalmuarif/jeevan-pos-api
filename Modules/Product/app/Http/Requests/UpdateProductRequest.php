<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Abstracts\BaseFormRequest;
use Modules\Core\Helpers\MerchantContext;
use Illuminate\Support\Facades\DB;

class UpdateProductRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';

    protected function isUpdate(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $emptyArrays = $this->input('__empty_arrays', []);
        if (! is_array($emptyArrays)) {
            return;
        }

        $normalized = [];
        foreach ($emptyArrays as $key) {
            if (in_array($key, ['category_ids', 'supplier_ids'], true)) {
                $normalized[$key] = [];
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        $merchantId = $this->merchantId();
        $catalogExists = fn ($query) => $query->whereNull('merchant_id')->orWhere('merchant_id', $merchantId);

        return [
            'type' => ['sometimes', Rule::in(['product', 'service'])],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')->where($catalogExists)],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where($catalogExists)],
            'supplier_ids' => ['sometimes', 'array'],
            'supplier_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('suppliers', 'id')
                    ->where('merchant_id', $this->merchantId())
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'track_stock' => ['sometimes', 'boolean'],
            'is_taxable' => ['sometimes', 'boolean'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'min_stock' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image_ids' => ['sometimes', 'array', 'max:5'],
            'remove_image_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $current = DB::table('products')->where('id', $this->route('product'))
                ->where('merchant_id', MerchantContext::id())->first(['type', 'track_stock', 'is_taxable', 'tax_rate']);
            $isTaxable = $this->has('is_taxable') ? $this->boolean('is_taxable') : (bool) $current?->is_taxable;
            $taxRate = $this->has('tax_rate') ? $this->input('tax_rate') : $current?->tax_rate;
            if ($isTaxable && $taxRate === null) {
                $validator->errors()->add('tax_rate', 'Tarif pajak wajib diisi ketika status kena pajak aktif.');
            }

            $type = $this->input('type', $current?->type);
            $trackStock = $this->has('track_stock') ? $this->boolean('track_stock') : (bool) $current?->track_stock;
            if ($type === 'service' && $trackStock) {
                $validator->errors()->add('track_stock', 'Produk jasa tidak dapat mengelola stok.');
            }
            if ($type === 'service' && ! empty($this->input('supplier_ids'))) {
                $validator->errors()->add('supplier_ids', 'Supplier hanya dapat dihubungkan dengan produk fisik.');
            }
        });
    }
}
