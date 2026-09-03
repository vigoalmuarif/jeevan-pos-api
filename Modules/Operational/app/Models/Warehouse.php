<?php

namespace Modules\Operational\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class Warehouse extends BaseModel
{
    use SoftDeletes, BelongsToMerchant;

    protected $casts = [
        'metadata' => 'array',
        'is_main'  => 'boolean',
    ];

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_warehouses');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(
            \Modules\Merchant\Models\Merchant::class
        );
    }

    public function isActive(): bool
    {
        return $this->is_active === true;
    }
}