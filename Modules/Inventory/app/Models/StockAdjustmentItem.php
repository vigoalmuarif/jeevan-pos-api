<?php

namespace Modules\Inventory\Models;

use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class StockAdjustmentItem extends BaseModel
{
    use BelongsToMerchant;
}
