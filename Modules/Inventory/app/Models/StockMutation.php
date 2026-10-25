<?php

namespace Modules\Inventory\Models;

use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class StockMutation extends BaseModel
{
    use BelongsToMerchant;

    public const UPDATED_AT = null;
}
