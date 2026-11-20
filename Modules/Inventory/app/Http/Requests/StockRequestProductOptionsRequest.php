<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockRequestProductOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'destination_warehouse_id' => ['nullable', 'integer', 'min:1'],
            'source_warehouse_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'exclude_variant_ids' => ['nullable', 'array'],
            'exclude_variant_ids.*' => ['integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}

