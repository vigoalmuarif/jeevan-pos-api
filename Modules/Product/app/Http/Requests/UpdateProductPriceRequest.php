<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Core\Abstracts\BaseFormRequest;

class UpdateProductPriceRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';
    public function rules(): array
    {
        $merchantId = $this->merchantId();
        return [
            'price_list_id' => ['sometimes', 'nullable', 'integer', Rule::exists('price_lists', 'id')->where('merchant_id', $merchantId)->where('is_active', true)],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->where('merchant_id', $merchantId)->where('is_active', true)],
            'min_qty' => ['sometimes', 'numeric', 'gt:0'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'pricing_mode' => ['sometimes', Rule::in(['fixed', 'margin', 'follow_center'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
