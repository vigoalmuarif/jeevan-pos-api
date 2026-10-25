<?php

namespace Modules\Operational\Models;

use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Helpers\MerchantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
// use Modules\Operational\Database\Factories\RegionFactory;
use Modules\Core\Traits\BelongsToMerchant;

class Region extends BaseModel
{
    use HasFactory, BelongsToMerchant;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'parent_region_id');
    }

    public function childs(): HasMany
    {
        return $this->hasMany(Region::class, 'parent_region_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_regions')
            ->wherePivot('merchant_id', MerchantContext::id())
            ->withPivot('merchant_id')
            ->withTimestamps();
    }
}
