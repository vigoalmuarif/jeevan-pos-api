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
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
