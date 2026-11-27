<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveStockRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'approved_items' => ['nullable', 'array'],
            'approved_items.*.item_id' => ['required', 'integer', 'min:1'],
            'approved_items.*.approved_base_quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}

