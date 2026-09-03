<?php
// Modules/Merchant/Models/PlanLimit.php

namespace Modules\Merchant\Models;

use Modules\Core\Abstracts\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanLimit extends BaseModel
{
    protected $fillable = ['plan_id', 'limit_key', 'limit_value'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}