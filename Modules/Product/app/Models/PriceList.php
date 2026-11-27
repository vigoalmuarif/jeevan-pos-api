<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class PriceList extends BaseModel
{
    use BelongsToMerchant;

    protected function casts(): array
    {
        return [
            'default_markup_percent' => 'float',
            'rounding_unit' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }
}
