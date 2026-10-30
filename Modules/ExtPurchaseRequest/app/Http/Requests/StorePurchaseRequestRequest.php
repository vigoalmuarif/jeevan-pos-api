<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'request_date' => ['required', 'date'],
            'required_date' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'in:manual,low_stock'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer'],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.unit_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.on_hand_base_quantity' => ['nullable', 'numeric', 'gte:0'],
            'items.*.suggested_supplier_id' => ['nullable', 'integer'],
            'items.*.estimated_unit_price' => ['nullable', 'numeric', 'gte:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
