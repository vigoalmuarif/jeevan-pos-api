<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Abstracts\BaseModel;

class Plan extends BaseModel
{
    protected $casts = [
        'metadata'  => 'array',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'price'     => 'decimal:2',
    ];


    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function currentPrice(string $billingCycle = 'monthly'): ?PlanPrice
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

    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }

    public function getLimit(string $key): int
    {
        return $this->limits()->where('limit_key', $key)->value('limit_value') ?? 0;
    }

    public function includedModules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_modules');
    }

    public function includesModuleForFree(Module $module): bool
    {
        return $module->category === 'core'
            || $this->includedModules()->where('modules.id', $module->id)->exists();
    }
}