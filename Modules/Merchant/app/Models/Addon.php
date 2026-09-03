<?php
// Modules/Merchant/Models/Addon.php

namespace Modules\Merchant\Models;

use Modules\Core\Abstracts\BaseModel;
use Illuminate\Database\Eloquent\Relations\{HasMany, BelongsTo};

class Addon extends BaseModel
{
    protected $fillable = [
        'code', 'name', 'description', 'type',
        'target_limit_key', 'bundle_quantity', 'module_id', 'is_active',
    ];

    public function prices(): HasMany
    {
        return $this->hasMany(AddonPrice::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function currentPrice(string $billingCycle = 'monthly'): ?AddonPrice
    {
        return $this->prices()
            ->where('billing_cycle', $billingCycle)
            ->where('valid_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>', now());
            })
            ->latest('valid_from')
            ->first();
    }

    public function isModuleType(): bool
    {
        return $this->type === 'module';
    }

    public function isQuantityType(): bool
    {
        return in_array($this->type, ['per_unit', 'bundle']);
    }
}