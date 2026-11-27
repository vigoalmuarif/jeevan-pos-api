<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            'destination_warehouse_id' => ['required', 'integer', 'min:1'],
            'source_warehouse_id' => ['nullable', 'integer', 'min:1', 'different:destination_warehouse_id'],
            'request_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:request_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.unit_id' => ['required', 'integer', 'min:1'],
            'items.*.unit_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}

