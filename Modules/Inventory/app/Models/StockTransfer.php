<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Core\Traits\HasAudit;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Operational\Models\Warehouse;

/**
 * Transfer stok antar gudang. Field alur kerja (status, shipped_*, received_*, cancelled_*,
 * receive_notes) sengaja TIDAK fillable; hanya diubah StockTransferService.
 */
class StockTransfer extends BaseModel
{
    use BelongsToMerchant, HasAudit, SoftDeletes;

    protected $fillable = ['document_number', 'from_warehouse_id', 'to_warehouse_id', 'transfer_date', 'notes'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'status' => StockTransferStatus::class,
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }
}