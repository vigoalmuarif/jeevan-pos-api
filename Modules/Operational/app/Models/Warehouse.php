<?php

namespace Modules\Operational\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Traits\BelongsToMerchant;

class Warehouse extends BaseModel
{
    use SoftDeletes, BelongsToMerchant;

    protected $casts = ['metadata' => 'array'];

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_warehouses')
            ->withPivot(['merchant_id', 'is_primary', 'is_active'])
            ->withTimestamps();
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'warehouse_regions')
            ->wherePivot('merchant_id', MerchantContext::id())
            ->withPivot('merchant_id')
            ->withTimestamps();
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