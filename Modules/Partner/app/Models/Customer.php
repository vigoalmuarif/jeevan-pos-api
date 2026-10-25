<?php

namespace Modules\Partner\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
// use Modules\Partner\Database\Factories\CustomerFactory;

class Customer extends BaseModel
{
    use HasFactory;
    use SoftDeletes;
    use BelongsToMerchant;

    // protected static function newFactory(): CustomerFactory
    // {
    //     // return CustomerFactory::new();
    // }
}
