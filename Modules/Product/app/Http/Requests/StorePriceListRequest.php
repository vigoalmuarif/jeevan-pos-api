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
            'default_markup_percent' => ['nullable', 'numeric', 'min:-99', 'max:1000'],
            'rounding_unit' => ['nullable', 'integer', Rule::in([0, 100, 500, 1000])],
            'rounding_mode' => ['nullable', 'string', Rule::in(['nearest', 'up', 'down'])],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
