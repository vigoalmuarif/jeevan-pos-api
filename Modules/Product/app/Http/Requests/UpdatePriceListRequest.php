<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Core\Abstracts\BaseFormRequest;

class UpdatePriceListRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';
    public function rules(): array
    {
        $merchantId = $this->merchantId();
        $id = $this->route('priceList');
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('price_lists', 'name')->where('merchant_id', $merchantId)->ignore($id)],
            'code' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('price_lists', 'code')->where('merchant_id', $merchantId)->ignore($id)],
            'default_markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:-99', 'max:1000'],
            'rounding_unit' => ['sometimes', 'nullable', 'integer', Rule::in([0, 100, 500, 1000])],
            'rounding_mode' => ['sometimes', 'nullable', 'string', Rule::in(['nearest', 'up', 'down'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
