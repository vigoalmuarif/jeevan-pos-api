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
use Modules\Purchase\Enums\DocumentStatus;
use Modules\Purchase\Enums\ReturnReason;
use Modules\Purchase\Enums\SettlementMethod;

/**
 * Purchase Return (Retur Pembelian) - DEC-10.
 *
 * Mengembalikan barang ke supplier dan menerbitkan Debit Note.
 */
class PurchaseReturn extends BaseModel
{
    use BelongsToMerchant, HasAudit, SoftDeletes;

    protected $fillable = [
        'branch_id', 'document_number', 'supplier_id', 'warehouse_id',
        'goods_receipt_id', 'purchase_invoice_id', 'return_date',
        'settlement_method', 'reason_code', 'subtotal', 'tax_amount',
        'grand_total', 'notes',
    ];

    protected $attributes = [
        'status' => 'draft',
        'settlement_method' => 'invoice_deduction',
        'reason_code' => 'damaged',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'status' => DocumentStatus::class,
            'settlement_method' => SettlementMethod::class,
            'reason_code' => ReturnReason::class,
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function scopeStatus(Builder $query, DocumentStatus|string $status): Builder
    {
        $value = $status instanceof DocumentStatus ? $status->value : $status;
        return $query->where('status', $value);
    }
}

