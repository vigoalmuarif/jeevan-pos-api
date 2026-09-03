<?php

namespace Modules\Merchant\Services;

use Modules\Core\Abstracts\BaseService;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantModule;
use Modules\Merchant\Models\Module;

class MerchantSubscriptionService extends BaseService
{
    /**
     * Aktivasi module business/addon untuk merchant — snapshot harga saat ini
     */
    public function activateModule(Merchant $merchant, Module $module, string $billingCycle = 'monthly'): MerchantModule
    {
        $plan = $merchant->activeSubscription->plan;

        // Module core ATAU included gratis di plan → tidak charge
        if ($plan->includesModuleForFree($module)) {
            return MerchantModule::updateOrCreate(
                ['merchant_id' => $merchant->id, 'module_id' => $module->id],
                [
                    'is_active' => true,
                    'price_snapshot' => null,
                    'currency_snapshot' => null,
                    'billing_cycle_snapshot' => null,
                    'module_price_id' => null,
                ]
            );
        }

        $price = $module->currentPrice($billingCycle);

        if (!$price) {
            throw new \RuntimeException("Module {$module->code} tidak memiliki harga aktif untuk {$billingCycle}");
        }

        return MerchantModule::updateOrCreate(
            ['merchant_id' => $merchant->id, 'module_id' => $module->id],
            [
                'is_active' => true,
                'price_snapshot' => $price->price,
                'currency_snapshot' => $price->currency,
                'billing_cycle_snapshot' => $billingCycle,
                'module_price_id' => $price->id,
            ]
        );
    }

    /**
     * Hitung total billing aktual berdasarkan snapshot (untuk invoice — TIDAK pakai harga live)
     */
    public function calculateBillingFromSnapshot(Merchant $merchant): array
    {
        $subscription = $merchant->activeSubscription; // asumsi relasi

        $planPriceSnapshot = $subscription->base_price_snapshot;

        $activeModules = MerchantModule::query()
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->whereNotNull('price_snapshot') // exclude core (null)
            ->get();

        $modulesTotal = $activeModules->sum('price_snapshot');

        return [
            'plan_price' => $planPriceSnapshot,
            'modules' => $activeModules->map(fn($m) => [
                'module_id' => $m->module_id,
                'price' => $m->price_snapshot,
                'currency' => $m->currency_snapshot,
            ])->values()->all(),
            'total' => $planPriceSnapshot + $modulesTotal,
        ];
    }
}
