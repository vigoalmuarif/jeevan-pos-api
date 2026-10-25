<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class AssortmentGroup extends BaseModel
{
    use BelongsToMerchant, SoftDeletes;

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'assortment_group_products')
            ->withPivot('merchant_id')->withTimestamps();
    }
}
