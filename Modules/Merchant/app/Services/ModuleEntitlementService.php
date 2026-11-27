<?php

namespace Modules\Merchant\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Abstracts\BaseService;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\Module;

class ModuleEntitlementService extends BaseService
{
    public const CACHE_PREFIX = 'merchant:';
    public const CACHE_SUFFIX = ':entitlements';
    public const CACHE_TTL = 3600;

    /**
     * Get list of entitled module codes for the given merchant.
     * Evaluated using DEC-04 precedence:
     * 1. Global modules (is_active = true)
     * 2. Explicit override in merchant_modules (manual or add-on)
     * 3. Subscriptions (active / trial) -> plan_modules
     *
     * @return array<int, string>
     */
    public function getEntitledModuleCodes(Merchant|int $merchant): array
    {
        $merchantId = $merchant instanceof Merchant ? $merchant->id : (int) $merchant;
        $cacheKey = self::CACHE_PREFIX . $merchantId . self::CACHE_SUFFIX;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($merchantId) {
            return $this->resolveEntitledModuleCodes($merchantId);
        });
    }

    /**
     * Check if a specific module code is entitled for the merchant.
     */
    public function isEntitled(Merchant|int $merchant, string $moduleCode): bool
    {
        $entitledCodes = $this->getEntitledModuleCodes($merchant);

        return in_array($moduleCode, $entitledCodes, true);
    }

    /**
     * Invalidate cached entitlements for a merchant.
     */
    public function clearCache(Merchant|int $merchant): void
    {
        $merchantId = $merchant instanceof Merchant ? $merchant->id : (int) $merchant;
        Cache::forget(self::CACHE_PREFIX . $merchantId . self::CACHE_SUFFIX);
    }

    /**
     * Resolve entitled modules from the database following DEC-04.
     *
     * @return array<int, string>
     */
    protected function resolveEntitledModuleCodes(int $merchantId): array
    {
        // 1. Globally active modules
        $globalModules = Module::query()
            ->where('is_active', true)
            ->pluck('code', 'id')
            ->all();

        if (empty($globalModules)) {
            return [];
        }

        $globalModuleIds = array_keys($globalModules);

        // 2. Explicit overrides in merchant_modules
        $merchantModules = DB::table('merchant_modules')
            ->where('merchant_id', $merchantId)
            ->whereIn('module_id', $globalModuleIds)
            ->get(['module_id', 'is_active']);

        $overrides = [];
        foreach ($merchantModules as $mm) {
            $overrides[$mm->module_id] = (bool) $mm->is_active;
        }

        // 3. Active/trial subscription's plan_modules
        $activeSubscription = DB::table('subscriptions')
            ->where('merchant_id', $merchantId)
            ->whereIn('status', ['active', 'trial'])
            ->where(function ($query) {
                $query->whereNull('current_period_end')
                      ->orWhere('current_period_end', '>', now());
            })
            ->latest('id')
            ->first();

        $planModuleIds = [];
        if ($activeSubscription) {
            $planModuleIds = DB::table('plan_modules')
                ->where('plan_id', $activeSubscription->plan_id)
                ->whereIn('module_id', $globalModuleIds)
                ->pluck('module_id')
                ->all();
        }

        $entitledCodes = [];

        foreach ($globalModules as $moduleId => $code) {
            // Check override in merchant_modules first
            if (array_key_exists($moduleId, $overrides)) {
                if ($overrides[$moduleId] === true) {
                    $entitledCodes[] = $code;
                }
                continue;
            }

            // Fallback to subscription plan_modules
            if (in_array($moduleId, $planModuleIds, true)) {
                $entitledCodes[] = $code;
            }
        }

        return array_values(array_unique($entitledCodes));
    }
}

