<?php
// Modules/Merchant/Models/IndustryPackagePrice.php

namespace Modules\Merchant\Models;

use Modules\Core\Abstracts\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndustryPackagePrice extends BaseModel
{
    protected $fillable = ['industry_package_id', 'billing_cycle', 'price', 'currency', 'valid_from', 'valid_to'];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'price' => 'decimal:2',
    ];

    public function industryPackage(): BelongsTo
    {
        return $this->belongsTo(IndustryPackage::class);
    }
}