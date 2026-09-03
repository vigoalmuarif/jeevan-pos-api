<?php

namespace Modules\Permission\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;
use Illuminate\Database\Eloquent\Builder;

class Permission extends SpatiePermission
{
    // Scope per group/module
    public function scopeForGroup(
        Builder $query,
        string $group
    ): Builder {
        return $query->where('group', $group);
    }


    // Scope custom permission milik merchant
    public function scopeForMerchant(
        Builder $query,
        int $merchantId
    ): Builder {
        return $query->where('guard_name', 'merchant');
    }
}