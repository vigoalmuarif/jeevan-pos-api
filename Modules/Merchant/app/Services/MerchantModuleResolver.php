<?php
// Modules/Merchant/Services/MerchantModuleResolver.php

namespace Modules\Merchant\Services;

use Modules\Core\Abstracts\BaseService;
use Modules\Merchant\Models\{Merchant, MerchantModule, MerchantAddon};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class MerchantModuleResolver extends BaseService
{
    /**
     * Sinkronisasi merchant_modules dari 3 sumber dengan prioritas: plan > package > addon
     */
    public function syncModules(Merchant $merchant): void
    {
        DB::transaction(function () use ($merchant) {
            $subscription = $merchant->activeSubscription;

            if (!$subscription) {
                // Tidak ada subscription aktif → nonaktifkan semua module
                MerchantModule::where('merchant_id', $merchant->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'deactivated_at' => now()]);

                return;
            }

            $plan = $subscription->plan;

            $planModuleIds = $plan->includedModules()->pluck('modules.id')->unique();

            $activePackageIds = $merchant->industryPackages()
                ->wherePivot('is_active', true)
                ->pluck('industry_packages.id');

            $packageModuleIds = $activePackageIds->isEmpty()
                ? collect()
                : DB::table('industry_package_modules')
                    ->whereIn('industry_package_id', $activePackageIds)
                    ->pluck('module_id')
                    ->unique();

            $addonModuleIds = MerchantAddon::query()
                ->where('merchant_id', $merchant->id)
                ->where('is_active', true)
                ->whereHas('addon', fn($q) => $q->where('type', 'module'))
                ->with('addon')
                ->get()
                ->pluck('addon.module_id')
                ->filter()
                ->unique();

            $allActiveModuleIds = $planModuleIds
                ->merge($packageModuleIds)
                ->merge($addonModuleIds)
                ->unique();

            MerchantModule::where('merchant_id', $merchant->id)
                ->whereNotIn('module_id', $allActiveModuleIds)
                ->where('is_active', true)
                ->update(['is_active' => false, 'deactivated_at' => now()]);

            $this->upsertModules($merchant, $planModuleIds, 'plan');
            $this->upsertModules($merchant, $packageModuleIds, 'package', exceptSources: ['plan']);
            $this->upsertModules($merchant, $addonModuleIds, 'addon', exceptSources: ['plan', 'package']);
        });
    }

    private function upsertModules(Merchant $merchant, Collection $moduleIds, string $source, array $exceptSources = []): void
    {
        foreach ($moduleIds as $moduleId) {
            if (!empty($exceptSources)) {
                $existsWithHigherPriority = MerchantModule::where('merchant_id', $merchant->id)
                    ->where('module_id', $moduleId)
                    ->whereIn('source', $exceptSources)
                    ->where('is_active', true)
                    ->exists();

                if ($existsWithHigherPriority) {
                    continue;
                }
            }

            MerchantModule::updateOrCreate(
                ['merchant_id' => $merchant->id, 'module_id' => $moduleId],
                ['is_active' => true, 'source' => $source, 'activated_at' => now(), 'deactivated_at' => null]
            );
        }
    }
}