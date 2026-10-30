<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Core\Traits\HasAudit;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\Warehouse;
use Modules\Partner\Models\Supplier;
use Modules\Purchase\Enums\DiscountType;
use Modules\Purchase\Enums\DocumentStatus;

/**
 * Goods Receipt (Penerimaan Barang) = SATU-SATUNYA dokumen pembelian yang menggerakkan stok
 * dan menjadi dasar HPP. Penagihan / hutang ada di PurchaseInvoice.
 *
 * Field yang sengaja TIDAK fillable (hanya diubah service):
 *  - status, posted_*, voided_*   : alur dokumen (GoodsReceiptService)
 *  - purchase_invoice_id          : hanya diisi/dikosongkan PurchaseInvoiceService, supaya
 *                                   aturan "satu GR hanya di satu faktur aktif" tidak bisa dilewati.
 */
class GoodsReceipt extends BaseModel
{
    use BelongsToMerchant, HasAudit, SoftDeletes;

    protected $fillable = [
        'branch_id', 'document_number', 'purchase_order_id', 'supplier_id', 'warehouse_id',
        'receipt_date', 'delivery_note_number',
        'is_tax_inclusive', 'tax_in_cost',
        'items_subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_amount', 'grand_total', 'notes',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'status' => DocumentStatus::class,
            'is_tax_inclusive' => 'boolean',
            // true = pajak ikut menjadi HPP; false = PPN masukan dikreditkan (tidak masuk HPP).
            'tax_in_cost' => 'boolean',
            'discount_type' => DiscountType::class,
            'items_subtotal' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * GR yang BOLEH difakturkan: sudah diposting, belum masuk faktur mana pun,
     * dan (dibatasi pemanggil) milik supplier yang sama.
     * Satu-satunya definisi "siap difakturkan" agar tidak tersebar di banyak query.
     */
    public function scopeUninvoiced(Builder $query): Builder
    {
        return $query
            ->where('status', DocumentStatus::Posted->value)
            ->whereNull('purchase_invoice_id');
    }

    /** GR yang sudah difakturkan tidak boleh di-void sebelum fakturnya di-void. */
    public function isInvoiced(): bool
    {
        return $this->purchase_invoice_id !== null;
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }
}