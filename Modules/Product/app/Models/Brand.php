<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchantOrGlobal;

// use Modules\Product\Database\Factories\BrandFactory;

class Brand extends BaseModel
{
    use HasFactory, BelongsToMerchantOrGlobal;

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
