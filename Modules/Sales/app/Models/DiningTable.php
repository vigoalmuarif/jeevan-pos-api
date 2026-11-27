<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Operational\Models\Branch;

class DiningTable extends BaseModel
{
    use BelongsToMerchant, SoftDeletes;

    protected $table = 'dining_tables';

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'guest_count' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(DiningArea::class, 'dining_area_id');
    }

    public function activeTransaction(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class, 'active_transaction_id');
    }
}
