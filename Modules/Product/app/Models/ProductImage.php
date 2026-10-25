<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class ProductImage extends BaseModel
{
    use BelongsToMerchant;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
