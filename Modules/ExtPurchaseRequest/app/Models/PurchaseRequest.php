<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Core\Traits\HasAudit;
use Modules\ExtPurchaseRequest\Enums\PurchaseRequestSource;
use Modules\ExtPurchaseRequest\Enums\PurchaseRequestStatus;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\Warehouse;

/**
 * Purchase Request (permintaan pembelian internal). Tidak menggerakkan stok maupun hutang.
 *
 * Field alur kerja (status, submitted_*, approved_*, rejected_*, cancelled_*, closed_*) sengaja
 * TIDAK fillable; hanya diubah PurchaseRequestService agar client tidak dapat melompati
 * alur persetujuan.
 */
class PurchaseRequest extends BaseModel
{
    use BelongsToMerchant, HasAudit, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'warehouse_id',
        'document_number',
        'request_date',
        'required_date',
        'source',
        'notes',
    ];

    protected $attributes = [
        'status' => 'draft',
        'source' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'required_date' => 'date',
            'source' => PurchaseRequestSource::class,
            'status' => PurchaseRequestStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
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
        return $this->hasMany(PurchaseRequestItem::class);
    }
}
