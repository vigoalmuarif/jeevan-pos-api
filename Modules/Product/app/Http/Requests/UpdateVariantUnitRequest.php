<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Core\Abstracts\BaseFormRequest;

class UpdateVariantUnitRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';
    public function rules(): array
    {
        $merchantId = $this->merchantId();
        return [
            'conversion_value' => ['sometimes', 'numeric', 'gt:0'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('product_variant_units', 'barcode')->where('merchant_id', $merchantId)->whereNull('deleted_at')->ignore($this->route('variantUnit'))],
            'cost' => ['sometimes', 'numeric', 'min:0'],
            'default_price' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
