<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchantOrGlobal;
// use Modules\Product\Database\Factories\CategoryFactory;

class Category extends BaseModel
{
    use HasFactory, BelongsToMerchantOrGlobal;

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_categories')->withPivot('is_primary')->withTimestamps();
    }

}
