<?php

namespace Modules\Operational\Models;

use Modules\Core\Abstracts\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
