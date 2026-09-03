<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchantOrGlobal;

// use Modules\Product\Database\Factories\UnitFactory;

class Unit extends BaseModel
{
    use HasFactory, BelongsToMerchantOrGlobal;

    /**
     * The attributes that are mass assignable.
     */

    // protected static function newFactory(): UnitFactory
    // {
    //     // return UnitFactory::new();
    // }
}
