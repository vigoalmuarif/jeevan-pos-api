<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Support\PosIndustryPresets;

class UpdateBranchPosSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'default_ui_mode' => ['nullable', 'string', Rule::in(PosIndustryPresets::allowedUiModes())],
            'print_template' => ['nullable', 'string', Rule::in(PosIndustryPresets::allowedPrintTemplates())],
            'service_charge_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'allow_decimal_qty' => ['nullable', 'boolean'],
            'enable_item_notes' => ['nullable', 'boolean'],
            'barcode_fast_scan' => ['nullable', 'boolean'],
            'require_customer' => ['nullable', 'boolean'],
            'enabled_order_types' => ['nullable', 'array', 'min:1'],
            'enabled_order_types.*' => ['string', Rule::in(PosIndustryPresets::allowedFnbOrderTypes())],
            'payment_flow' => ['nullable', 'string', Rule::in(PosIndustryPresets::allowedPaymentFlows())],
            'auto_cleaning_after_payment' => ['nullable', 'boolean'],
            'channel_price_lists' => ['nullable', 'array'],
            'channel_price_lists.dine_in' => ['nullable', 'integer', 'min:1'],
            'channel_price_lists.takeaway' => ['nullable', 'integer', 'min:1'],
            'channel_price_lists.delivery' => ['nullable', 'integer', 'min:1'],
            'delivery_channels' => ['nullable', 'array', 'max:20'],
            'delivery_channels.*.code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/i'],
            'delivery_channels.*.name' => ['required', 'string', 'max:60'],
            'delivery_channels.*.price_list_id' => ['nullable', 'integer', 'min:1'],
            'extra_config' => ['nullable', 'array'],
        ];
    }
}
