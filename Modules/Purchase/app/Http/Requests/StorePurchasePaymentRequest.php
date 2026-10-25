<?php

namespace Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;
use Modules\Purchase\Enums\PaymentMethod;

/**
 * Membuat pembayaran ke supplier dengan alokasi ke faktur. Izin diperiksa di route.
 * Aturan bisnis (faktur posted, belum lunas, supplier sama, nominal <= sisa hutang) diperiksa
 * service di bawah kunci baris; di sini hanya format dan kepemilikan merchant.
 */
class StorePurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('merchant_id', MerchantContext::id())],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Opsional: bila dikirim harus sama dengan jumlah alokasi (cegah salah ketik).
            'amount' => ['nullable', 'decimal:0,4'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.purchase_invoice_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'decimal:0,4', 'gt:0'],
        ];
    }
}