<?php
// Modules/Merchant/Models/PlanPrice.php

namespace Modules\Merchant\Models;

use Modules\Core\Abstracts\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanPrice extends BaseModel
{
    protected $fillable = ['plan_id', 'billing_cycle', 'price', 'currency', 'valid_from', 'valid_to'];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'price' => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}