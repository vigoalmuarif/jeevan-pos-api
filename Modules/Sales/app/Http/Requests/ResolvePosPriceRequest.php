<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolvePosPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_variant_unit_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'price_list_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
