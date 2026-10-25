<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Core\Abstracts\BaseFormRequest;

class StoreVariantUnitRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';
    public function rules(): array
    {
        $merchantId = $this->merchantId();
        return [
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where(fn ($q) => $q->whereNull('merchant_id')->orWhere('merchant_id', $merchantId))],
            'conversion_value' => ['required', 'numeric', 'gt:0'],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('product_variant_units', 'barcode')->where('merchant_id', $merchantId)->whereNull('deleted_at')],
            'cost' => ['sometimes', 'numeric', 'min:0'],
            'default_price' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
