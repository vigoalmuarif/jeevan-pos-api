<?php
// Modules/Merchant/Models/MerchantAddon.php

namespace Modules\Merchant\Models;

use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantAddon extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id', 'addon_id', 'quantity', 'is_active',
        'price_snapshot', 'currency_snapshot', 'billing_cycle_snapshot',
        'addon_price_id', 'activated_at', 'deactivated_at',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}