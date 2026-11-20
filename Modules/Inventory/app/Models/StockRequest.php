<?php

namespace Modules\Inventory\Models;

use Modules\User\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Core\Traits\HasAudit;
use Modules\Inventory\Enums\StockRequestStatus;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\Warehouse;

class StockRequest extends BaseModel
{
    use BelongsToMerchant, HasAudit, SoftDeletes;

    protected $fillable = [
        'document_number',
        'branch_id',
        'destination_warehouse_id',
        'source_warehouse_id',
        'request_date',
        'expected_date',
        'notes',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'expected_date' => 'date',
            'status' => StockRequestStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockRequestItem::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
