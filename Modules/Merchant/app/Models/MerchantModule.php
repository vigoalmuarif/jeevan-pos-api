<?php
// Modules/Merchant/Models/MerchantModule.php

namespace Modules\Merchant\Models;

use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantModule extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'merchant_id', 'module_id', 'is_active', 'source',
        'activated_at', 'deactivated_at',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $mm) {
            if ($mm->merchant_id) {
                app(\Modules\Merchant\Services\ModuleEntitlementService::class)->clearCache($mm->merchant_id);
            }
        });

        static::deleted(function (self $mm) {
            if ($mm->merchant_id) {
                app(\Modules\Merchant\Services\ModuleEntitlementService::class)->clearCache($mm->merchant_id);
            }
        });
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}