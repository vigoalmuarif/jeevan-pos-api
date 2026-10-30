<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConvertPurchaseRequestToOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.supplier_id' => ['required', 'integer'],
            'groups.*.warehouse_id' => ['required', 'integer'],
            'groups.*.branch_id' => ['nullable', 'integer'],
            'groups.*.order_date' => ['required', 'date'],
            'groups.*.expected_date' => ['nullable', 'date'],
            'groups.*.is_tax_inclusive' => ['nullable', 'boolean'],
            'groups.*.discount_type' => ['nullable', 'string', 'in:percent,amount'],
            'groups.*.discount_value' => ['nullable', 'numeric', 'gte:0'],
            'groups.*.notes' => ['nullable', 'string', 'max:1000'],

            'groups.*.items' => ['required', 'array', 'min:1'],
            'groups.*.items.*.purchase_request_item_id' => ['required', 'integer'],
            'groups.*.items.*.unit_id' => ['nullable', 'integer'],
            'groups.*.items.*.unit_quantity' => ['required', 'numeric', 'gt:0'],
            'groups.*.items.*.unit_price' => ['required', 'numeric', 'gte:0'],
            'groups.*.items.*.discount_type' => ['nullable', 'string', 'in:percent,amount'],
            'groups.*.items.*.discount_value' => ['nullable', 'numeric', 'gte:0'],
            'groups.*.items.*.tax_rate' => ['nullable', 'numeric', 'gte:0', 'lte:100'],
            'groups.*.items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
