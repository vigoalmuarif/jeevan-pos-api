<?php
// Modules/Merchant/Models/IndustryPackage.php

namespace Modules\Merchant\Models;

use Modules\Core\Abstracts\BaseModel;
use Illuminate\Database\Eloquent\Relations\{HasMany, BelongsToMany};

class IndustryPackage extends BaseModel
{
    protected $fillable = ['code', 'name', 'description', 'is_active'];

    public function prices(): HasMany
    {
        return $this->hasMany(IndustryPackagePrice::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'industry_package_modules');
    }

    public function currentPrice(string $billingCycle = 'monthly'): ?IndustryPackagePrice
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
}