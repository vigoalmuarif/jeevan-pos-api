<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
// use Modules\Product\Database\Factories\CategoryFactory;

class Category extends BaseModel
{
    use HasFactory, BelongsToMerchant;

}
