<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Enums\ReturnFeeBearer;
use Modules\Sales\Enums\SalesPaymentMethod;
use Modules\Sales\Enums\SalesReturnType;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sales_transaction_id' => ['required', 'integer', 'min:1'],
            'cashier_shift_id' => ['nullable', 'integer', 'min:1'],
            'return_type' => ['sometimes', 'string', Rule::in(array_map(fn (SalesReturnType $t) => $t->value, SalesReturnType::cases()))],
            'refund_method' => ['required', 'string', Rule::in(array_map(fn (SalesPaymentMethod $m) => $m->value, SalesPaymentMethod::cases()))],
            'return_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'return_fee_bearer' => ['nullable', 'string', Rule::in(array_map(fn (ReturnFeeBearer $b) => $b->value, ReturnFeeBearer::cases()))],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_transaction_item_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.restock' => ['sometimes', 'boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:300'],
            'exchange_items' => ['required_if:return_type,exchange', 'array'],
            'exchange_items.*.product_variant_unit_id' => ['required_with:exchange_items', 'integer', 'min:1'],
            'exchange_items.*.quantity' => ['required_with:exchange_items', 'numeric', 'gt:0', 'max:9999999'],
            'exchange_items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'exchange_items.*.notes' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function messages(): array
    {
        return [
            'sales_transaction_id.required' => 'Transaksi penjualan yang akan diretur wajib dipilih.',
            'refund_method.required' => 'Metode penyelesaian dana wajib dipilih.',
            'reason.required' => 'Alasan retur wajib diisi.',
            'reason.min' => 'Alasan retur minimal 3 karakter.',
            'items.required' => 'Minimal satu item retur wajib dipilih.',
            'items.*.quantity.gt' => 'Qty retur harus lebih dari nol.',
            'exchange_items.required_if' => 'Minimal satu barang pengganti wajib dipilih untuk retur tukar barang.',
            'exchange_items.*.quantity.gt' => 'Qty barang pengganti harus lebih dari nol.',
        ];
    }
}
