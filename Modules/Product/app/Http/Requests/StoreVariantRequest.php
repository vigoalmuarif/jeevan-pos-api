<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Abstracts\BaseFormRequest;

class StoreVariantRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';

    public function rules(): array
    {
        $merchantId = $this->merchantId();
        $unitExists = fn ($query) => $query->whereNull('merchant_id')->orWhere('merchant_id', $merchantId);

        return [
            'sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')->where('merchant_id', $merchantId)],
            'name' => ['nullable', 'string', 'max:255'],
            'base_unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where($unitExists)],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variant_units', 'barcode')->where('merchant_id', $merchantId)->whereNull('deleted_at')],
            'cost' => ['sometimes', 'numeric', 'min:0'],
            'default_price' => ['sometimes', 'numeric', 'min:0'],
            'additional_units' => ['sometimes', 'array'],
            'additional_units.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where($unitExists)],
            'additional_units.*.conversion_value' => ['required', 'numeric', 'gt:0'],
            'additional_units.*.barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variant_units', 'barcode')->where('merchant_id', $merchantId)->whereNull('deleted_at')],
            'additional_units.*.cost' => ['sometimes', 'numeric', 'min:0'],
            'additional_units.*.default_price' => ['sometimes', 'numeric', 'min:0'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $unitIds = collect($this->input('additional_units', []))->pluck('unit_id');
            if ($unitIds->contains((int) $this->input('base_unit_id'))) {
                $validator->errors()->add('additional_units', 'Satuan dasar tidak boleh diulang sebagai satuan tambahan.');
            }
            if ($unitIds->duplicates()->isNotEmpty()) {
                $validator->errors()->add('additional_units', 'Satuan tambahan tidak boleh duplikat.');
            }
            $barcodes = collect([$this->input('barcode')])->merge(collect($this->input('additional_units', []))->pluck('barcode'))->filter();
            if ($barcodes->duplicates()->isNotEmpty()) {
                $validator->errors()->add('additional_units', 'Barcode pada varian tidak boleh duplikat.');
            }
        });
    }
}
