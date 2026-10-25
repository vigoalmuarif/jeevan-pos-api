<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

/**
 * Mengubah header faktur draft. supplier_id dan himpunan penerimaan tidak dapat diubah
 * (hapus draft lalu buat ulang), sehingga tidak ada di sini.
 */
class UpdatePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('merchant_id', MerchantContext::id())],
            'supplier_invoice_number' => ['required', 'string', 'max:50'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'rounding_amount' => ['nullable', 'decimal:0,4'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}