<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Core\Abstracts\BaseFormRequest;

class StorePriceListRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';
    public function rules(): array
    {
        $merchantId = $this->merchantId();
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('price_lists', 'name')->where('merchant_id', $merchantId)],
            'code' => ['nullable', 'string', 'max:255', Rule::unique('price_lists', 'code')->where('merchant_id', $merchantId)],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
