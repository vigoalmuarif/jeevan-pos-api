<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Abstracts\BaseModel;
use Modules\Operational\Models\Branch;
use Modules\Merchant\Models\Module;
use Modules\Merchant\Models\Plan;
use Modules\Merchant\Models\Subscription;
use Modules\Merchant\Models\MerchantModule;
use Modules\User\Models\User;

class Merchant extends BaseModel
{
    use SoftDeletes;

    protected $casts = [
        'metadata' => 'array',
    ];

    // Relations
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function mainBranch(): HasOne
    {
        return $this->hasOne(Branch::class)
            ->where('is_main', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', ['trial', 'active', 'past_due'])
            ->latest('current_period_start');
    }

    public function primaryIndustryPackage(): BelongsTo
    {
        return $this->belongsTo(IndustryPackage::class, 'industry_code', 'code');
    }

    public function merchantModules(): HasMany
    {
        return $this->hasMany(MerchantModule::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(
            Module::class,
            'merchant_modules'
        )->withPivot(['is_active', 'granted_by', 'expires_at'])
            ->withTimestamps();
    }

    // Helpers
    public function hasModule(string $moduleCode): bool
    {
        return $this->merchantModules()
            ->whereHas(
                'module',
                fn($q) =>
                $q->where('code', $moduleCode)
            )
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOnTrial(): bool
    {
        $sub = $this->activeSubscription;

        return $sub?->status === 'trial' && $sub?->trial_ends_at?->isFuture();
    }


    public function industryPackages(): BelongsToMany
    {
        return $this->belongsToMany(IndustryPackage::class, 'merchant_industry_packages')
            ->withPivot(['is_active', 'price_snapshot', 'currency_snapshot', 'billing_cycle_snapshot', 'industry_package_price_id', 'activated_at', 'deactivated_at']);
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'merchant_addons')
            ->withPivot(['quantity', 'is_active', 'price_snapshot', 'currency_snapshot', 'billing_cycle_snapshot', 'addon_price_id', 'activated_at', 'deactivated_at']);
    }

    public function activeModules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'merchant_modules')
            ->wherePivot('is_active', true);
    }
}