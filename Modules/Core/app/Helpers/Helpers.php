<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Helpers\MerchantContext;

if (! function_exists('uniqueSlug')) {
    function uniqueSlug($table_name, string $name, ?int $ignoreId = null): string
    {
        $merchantId = (int) MerchantContext::id();
        $base = Str::slug($name) ?: 'branch';
        $slug = $base;
        $suffix = 2;

        $table = DB::table($table_name)
            ->where('merchant_id', $merchantId)
            ->when(
                $ignoreId,
                fn($query) => $query->where('id', '!=', $ignoreId)
            )
            ->where('slug', $slug)
            ->exists();

        while ($table) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
