<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Enums\SalesOrderType;
use Modules\Sales\Enums\SalesPaymentMethod;
use Modules\Sales\Enums\SalesTransactionStatus;

class ListSalesTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'cashier_shift_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(array_column(SalesTransactionStatus::cases(), 'value'))],
            'order_type' => ['nullable', 'string', Rule::in(array_column(SalesOrderType::cases(), 'value'))],
            'sales_channel' => ['nullable', 'string', 'max:60'],
            'payment_method' => ['nullable', 'string', Rule::in(array_column(SalesPaymentMethod::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
