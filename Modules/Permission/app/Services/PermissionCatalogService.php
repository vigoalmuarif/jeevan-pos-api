<?php

namespace Modules\Permission\Services;

use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

class PermissionCatalogService
{
    private const CACHE_KEY = 'permission.catalog.all-names';

    public function allNames(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return Permission::whereNotIn('name', ['core.dashboard.view'])?->pluck('name')->all();
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}