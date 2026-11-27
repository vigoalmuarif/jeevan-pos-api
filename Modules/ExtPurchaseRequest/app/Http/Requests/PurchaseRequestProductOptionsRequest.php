<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class PurchaseRequestProductOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $merchantId = MerchantContext::id();

        return [
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')
                    ->where('merchant_id', $merchantId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')
                    ->where('merchant_id', $merchantId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'can_be_sold' => ['nullable', 'boolean'],
            'exclude_variant_ids' => ['nullable', 'array'],
            'exclude_variant_ids.*' => ['integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
