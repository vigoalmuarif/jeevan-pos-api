<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;
use Modules\Purchase\Enums\ReturnReason;
use Modules\Purchase\Enums\SettlementMethod;

class UpdatePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $merchantId = MerchantContext::id();

        return [
            'goods_receipt_id' => [
                'required', 'integer',
                Rule::exists('goods_receipts', 'id')
                    ->where('merchant_id', $merchantId)
                    ->where('status', 'posted')
                    ->whereNull('deleted_at'),
            ],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')
                    ->where('merchant_id', $merchantId)
                    ->whereNull('deleted_at'),
            ],
            'return_date' => ['required', 'date'],
            'settlement_method' => ['required', Rule::enum(SettlementMethod::class)],
            'reason_code' => ['required', Rule::enum(ReturnReason::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.goods_receipt_item_id' => ['required', 'integer', 'min:1'],
            'items.*.unit_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}

