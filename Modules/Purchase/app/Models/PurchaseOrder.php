<?php

namespace Modules\Purchase\Models;

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
use Modules\Purchase\Enums\PurchaseOrderStatus;

/**
 * Purchase Order (opsional dipakai merchant). Tidak menggerakkan stok.
 *
 * Catatan mass assignment:
 *  - $fillable HANYA berisi data input + nilai hasil PurchaseCalculator.
 *  - Field alur kerja (status, approved_*, cancelled_*) sengaja TIDAK fillable; hanya
 *    boleh diubah PurchaseOrderService lewat forceFill()/assignment eksplisit, sehingga
 *    request dari client tidak bisa melompati alur approval.
 *  - merchant_id diisi otomatis oleh trait BelongsToMerchant.
 */
class PurchaseOrder extends BaseModel
{
    // Hapus trait yang sudah dipakai BaseModel jika BaseModel Anda sudah memuatnya.
    use BelongsToMerchant, HasAudit, SoftDeletes;

    protected $fillable = [
        'branch_id', 'document_number', 'supplier_id', 'warehouse_id',
        'order_date', 'expected_date', 'is_tax_inclusive',
        'items_subtotal', 'discount_type', 'discount_value', 'discount_amount',
        'tax_amount', 'grand_total', 'notes',
    ];

    // Default sama dengan default kolom di database agar model baru langsung konsisten.
    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'status' => PurchaseOrderStatus::class,
            'is_tax_inclusive' => 'boolean',
            'discount_type' => DiscountType::class,
            // Cast decimal mengembalikan STRING (bukan float) agar presisi uang terjaga.
            // Lakukan perhitungan dengan bcmath / PurchaseCalculator, jangan operator float.
            'items_subtotal' => 'decimal:4',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Gudang tujuan penerimaan. */
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
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Satu PO dapat memiliki banyak penerimaan (penerimaan parsial). */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}