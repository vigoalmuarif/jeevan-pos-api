<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchantOrGlobal;

class Attribute extends BaseModel
{
    use BelongsToMerchantOrGlobal, SoftDeletes;

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }
}
