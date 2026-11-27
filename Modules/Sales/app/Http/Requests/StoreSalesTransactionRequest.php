<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Enums\SalesDiscountType;
use Modules\Sales\Enums\SalesOrderType;
use Modules\Sales\Enums\SalesPaymentMethod;
use Modules\Sales\Enums\SalesTransactionStatus;

class StoreSalesTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $discountTypes = array_column(SalesDiscountType::cases(), 'value');
        $orderTypes = array_column(SalesOrderType::cases(), 'value');
        $paymentMethods = array_column(SalesPaymentMethod::cases(), 'value');

        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            'cashier_shift_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'price_list_id' => ['nullable', 'integer', 'min:1'],
            'sales_channel' => ['nullable', 'string', 'max:60'],
            'channel_order_ref' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'string', Rule::in([
                SalesTransactionStatus::Completed->value,
                SalesTransactionStatus::Held->value,
            ])],
            'order_type' => ['nullable', 'string', Rule::in($orderTypes)],
            'dining_table_id' => ['nullable', 'integer', 'min:1'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:99'],
            'discount_type' => ['nullable', 'string', Rule::in($discountTypes)],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'hold_label' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_unit_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.discount_type' => ['nullable', 'string', Rule::in($discountTypes)],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.modifiers' => ['nullable', 'array'],
            'items.*.modifiers.*.modifier_option_id' => ['required_with:items.*.modifiers', 'integer', 'min:1'],
            'items.*.modifiers.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],

            'payments' => ['nullable', 'array'],
            'payments.*.payment_method' => ['required_with:payments', 'string', Rule::in($paymentMethods)],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'gt:0', 'max:999999999999'],
            'payments.*.reference_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
