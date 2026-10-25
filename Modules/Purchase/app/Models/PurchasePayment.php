<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Core\Traits\HasAudit;
use Modules\Partner\Models\Supplier;
use Modules\Purchase\Enums\DocumentStatus;
use Modules\Purchase\Enums\PaymentMethod;

/**
 * Pembayaran ke supplier. Satu pembayaran dapat dialokasikan ke satu atau beberapa faktur
 * (PurchasePaymentAllocation). Tahap 1: seluruh nominal wajib teralokasi, dan semua faktur
 * harus milik supplier yang sama (divalidasi PurchasePaymentService).
 *
 * Tidak memakai SoftDeletes: pembayaran yang salah dibatalkan (voided), bukan dihapus.
 * status, voided_* tidak fillable; hanya diubah service.
 */
class PurchasePayment extends BaseModel
{
    use BelongsToMerchant, HasAudit;

    protected $fillable = [
        'document_number', 'supplier_id', 'payment_date', 'payment_method',
        'amount', 'reference_number', 'notes',
    ];

    // Pembayaran langsung berstatus posted (tidak ada draft).
    protected $attributes = ['status' => 'posted'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'payment_method' => PaymentMethod::class,
            'status' => DocumentStatus::class,
            'amount' => 'decimal:4',
            'voided_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchasePaymentAllocation::class);
    }

    /** Faktur yang dilunasi pembayaran ini, untuk DIBACA saja (lihat catatan di PurchaseInvoice::payments). */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseInvoice::class, 'purchase_payment_allocations')
            ->withPivot('amount')
            ->withTimestamps();
    }
}