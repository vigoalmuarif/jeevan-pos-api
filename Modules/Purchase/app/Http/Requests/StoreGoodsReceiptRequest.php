<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $merchantId = MerchantContext::id();

        return [
            'supplier_id' => ['required', 'integer', 'min:1', Rule::exists('suppliers', 'id')->where('merchant_id', $merchantId)->where('is_active', true)->whereNull('deleted_at')],
            'warehouse_id' => ['required', 'integer', 'min:1', Rule::exists('warehouses', 'id')->where('merchant_id', $merchantId)->where('is_active', true)->whereNull('deleted_at')],
            'receipt_date' => ['required', 'date'],
            'delivery_note_number' => ['nullable', 'string', 'max:50'],
            'is_tax_inclusive' => ['sometimes', 'boolean'],
            'discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.unit_id' => ['required', 'integer', 'min:1'],
            'items.*.unit_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
