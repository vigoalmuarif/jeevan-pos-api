<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', Rule::in(['damaged', 'expired', 'lost', 'found', 'correction', 'opening_stock', 'other'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.unit_id' => ['required', 'integer', 'min:1'],
            'items.*.unit_quantity' => [
                'required', 'numeric',
                $this->input('reason') === 'opening_stock' ? 'gt:0' : 'between:-999999999999.9999,999999999999.9999',
                ...($this->input('reason') === 'opening_stock' ? [] : ['not_in:0']),
            ],
            'items.*.unit_cost' => [$this->input('reason') === 'opening_stock' ? 'required' : 'nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
