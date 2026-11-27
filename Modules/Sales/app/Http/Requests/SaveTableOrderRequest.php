<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Enums\SalesDiscountType;

class SaveTableOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $discountTypes = array_column(SalesDiscountType::cases(), 'value');

        return [
            'cashier_shift_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:99'],
            'discount_type' => ['nullable', 'string', Rule::in($discountTypes)],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:500'],
            'void_reason_code' => ['nullable', 'string', Rule::in(['wrong_input', 'guest_cancelled', 'out_of_stock', 'kitchen_issue', 'other'])],
            'void_reason_note' => ['nullable', 'string', 'max:255'],
            'supervisor_pin' => ['nullable', 'string', 'max:20'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_unit_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.discount_type' => ['nullable', 'string', Rule::in($discountTypes)],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.course_number' => ['nullable', 'integer', 'min:1', 'max:3'],
            'items.*.is_held_for_kitchen' => ['nullable', 'boolean'],
            'items.*.modifiers' => ['nullable', 'array'],
            'items.*.modifiers.*.modifier_option_id' => ['required_with:items.*.modifiers', 'integer', 'min:1'],
            'items.*.modifiers.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }
}
