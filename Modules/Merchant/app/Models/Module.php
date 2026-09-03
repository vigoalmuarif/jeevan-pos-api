<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Permission\Models\Menu;

class Module extends BaseModel
{
    protected $casts = [
        'metadata'  => 'array',
        'is_active' => 'boolean',
    ];

    public function industries(): BelongsToMany
    {
        return $this->belongsToMany(IndustryPackage::class, 'industry_package_code')
                    ->withTimestamps();
    }

    
    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function fetures(): BelongsToMany
    {
        return $this->belongsToMany(FeatureModule::class, 'feature_modules');
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_modules')
                    ->withTimestamps();
    }

    public function merchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class, 'merchant_modules')
                    ->withPivot(['is_active', 'granted_by', 'expires_at'])
                    ->withTimestamps();
    }

    public function isCore(): bool
    {
        return $this->type === 'core';
    }
}