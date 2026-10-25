<?php

namespace Modules\Product\Models;

use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class BranchProductOverride extends BaseModel
{
    use BelongsToMerchant;
}
