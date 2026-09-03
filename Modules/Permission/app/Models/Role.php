<?php

namespace Modules\Permission\Models;

use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Builder;

class Role extends SpatieRole
{
    // Scope untuk ambil role milik merchant tertentu
    public function scopeForMerchant(
        Builder $query,
        int $merchantId
    ): Builder {
        return $query->where('merchant_id', $merchantId);
    }

    // Scope untuk ambil role global (tanpa merchant)
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('merchant_id');
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }
}