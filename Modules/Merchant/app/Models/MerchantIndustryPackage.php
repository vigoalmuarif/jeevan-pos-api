<?php
// Modules/Merchant/Models/MerchantIndustryPackage.php

namespace Modules\Merchant\Models;

use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantIndustryPackage extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id', 'industry_package_id', 'is_active',
        'price_snapshot', 'currency_snapshot', 'billing_cycle_snapshot',
        'industry_package_price_id', 'activated_at', 'deactivated_at',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function industryPackage(): BelongsTo
    {
        return $this->belongsTo(IndustryPackage::class);
    }
}