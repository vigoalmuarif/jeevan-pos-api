<?php

namespace Modules\Inventory\Models;

use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class WarehouseStock extends BaseModel
{
    use BelongsToMerchant;
}
