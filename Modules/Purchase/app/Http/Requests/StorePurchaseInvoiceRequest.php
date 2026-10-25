<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

/**
 * Membuat faktur dari penerimaan barang. Izin diperiksa di route (middleware can:).
 * Aturan bisnis (GR harus posted, belum difakturkan, supplier sama) diperiksa service
 * di bawah kunci baris; di sini hanya format dan kepemilikan merchant.
 */
class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $merchantId = MerchantContext::id();

        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('merchant_id', $merchantId)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('merchant_id', $merchantId)],
            'supplier_invoice_number' => ['required', 'string', 'max:50'],
            'invoice_date' => ['required', 'date'],
            // Kosong = diisi otomatis dari tempo pembayaran supplier.
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            // Selisih pembulatan dengan tagihan supplier (bertanda, maks. 4 desimal).
            'rounding_amount' => ['nullable', 'decimal:0,4'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'goods_receipt_ids' => ['required', 'array', 'min:1'],
            'goods_receipt_ids.*' => ['integer', 'distinct'],
        ];
    }
}