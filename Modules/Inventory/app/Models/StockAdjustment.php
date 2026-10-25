<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class StockAdjustment extends BaseModel
{
    use SoftDeletes, BelongsToMerchant;

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }
}
