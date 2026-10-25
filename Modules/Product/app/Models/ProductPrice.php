<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class ProductPrice extends BaseModel
{
    use BelongsToMerchant, SoftDeletes;

    protected function casts(): array
    {
        return ['min_qty' => 'decimal:4', 'price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function variantUnit(): BelongsTo
    {
        return $this->belongsTo(ProductVariantUnit::class, 'product_variant_unit_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
