<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

/**
 * Alokasi pembayaran ke faktur - dasar pelunasan bertahap.
 * Dibuat hanya oleh PurchasePaymentService bersamaan dengan pembaruan
 * purchase_invoices.paid_amount dalam SATU transaksi database.
 */
class PurchasePaymentAllocation extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = ['purchase_invoice_id', 'amount'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(PurchasePayment::class, 'purchase_payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }
}