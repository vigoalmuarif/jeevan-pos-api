<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Core\Traits\HasAudit;
use Modules\Operational\Models\Branch;
use Modules\Partner\Models\Supplier;
use Modules\Purchase\Enums\DocumentStatus;
use Modules\Purchase\Enums\PaymentStatus;

/**
 * Faktur Pembelian = tagihan dari supplier, dasar hutang dan pelunasan bertahap.
 * Tidak menggerakkan stok.
 *
 * PENTING:
 *  - paid_amount dan payment_status TIDAK fillable. Keduanya hanya boleh diubah
 *    PurchasePaymentService di dalam transaksi dengan lockForUpdate pada baris faktur,
 *    supaya dua pembayaran bersamaan tidak saling menimpa.
 *  - balance_amount adalah GENERATED COLUMN di PostgreSQL (grand_total - paid_amount).
 *    Jangan pernah di-set dari aplikasi; model hanya membacanya.
 */
class PurchaseInvoice extends BaseModel
{
    use BelongsToMerchant, HasAudit, SoftDeletes;

    protected $fillable = [
        'branch_id', 'document_number', 'supplier_id',
        'supplier_invoice_number', 'invoice_date', 'due_date',
        'items_subtotal', 'discount_amount', 'tax_amount', 'rounding_amount', 'grand_total',
        'notes',
    ];

    protected $attributes = [
        'status' => 'draft',
        'payment_status' => 'unpaid',
        'paid_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'status' => DocumentStatus::class,
            'payment_status' => PaymentStatus::class,
            'items_subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'rounding_amount' => 'decimal:4', // bertanda (+/-)
            'grand_total' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'balance_amount' => 'decimal:4',  // read-only (generated column)
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Faktur yang MASIH BISA DIBAYAR: sudah diposting dan belum lunas.
     * Dipakai form pembayaran untuk menampilkan daftar hutang berjalan per supplier.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query
            ->where('status', DocumentStatus::Posted->value)
            ->where('payment_status', '!=', PaymentStatus::Paid->value);
    }

    public function isPayable(): bool
    {
        return $this->status === DocumentStatus::Posted
            && $this->payment_status !== PaymentStatus::Paid;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    /** Penerimaan barang yang ditagih oleh faktur ini (goods_receipts.purchase_invoice_id). */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PurchasePaymentAllocation::class);
    }

    /**
     * Pembayaran yang melunasi faktur ini (via tabel alokasi), untuk DIBACA saja.
     * Jangan memakai attach()/sync() pada relasi ini: kolom merchant_id pada tabel
     * alokasi wajib diisi dan tidak akan terisi lewat pivot. Buat baris alokasi lewat
     * PurchasePaymentAllocation (dari PurchasePaymentService).
     */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(PurchasePayment::class, 'purchase_payment_allocations')
            ->withPivot('amount')
            ->withTimestamps();
    }
}