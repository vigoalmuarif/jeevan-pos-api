<?php

namespace Modules\Merchant\Services;

use Modules\Core\Abstracts\BaseService;
use Modules\Merchant\Models\Merchant;

class PricingService extends BaseService
{
    /**
     * Total billing dari snapshot (untuk invoice) live
     */
    public function calculateBillingFromSnapshot(Merchant $merchant): array
    {
        $subscription = $merchant->activeSubscription;
        $planPrice = $subscription->base_price_snapshot;

        $packages = $merchant->industryPackages()
            ->wherePivot('is_active', true)
            ->get()
            ->map(fn($pkg) => [
                'package_code' => $pkg->code,
                'package_name' => $pkg->name,
                'price' => $pkg->pivot->price_snapshot,
            ]);

        $addons = $merchant->addons()
            ->where('merchant_addons.is_active', true)
            ->get()
            ->map(fn($addon) => [
                'addon_code' => $addon->code,
                'addon_name' => $addon->name,
                'quantity' => $addon->pivot->quantity,
                'price_per_unit' => $addon->pivot->price_snapshot,
                'subtotal' => $addon->pivot->price_snapshot * $addon->pivot->quantity,
            ]);

        $total = $planPrice
            + $packages->sum('price')
            + $addons->sum('subtotal');

        return [
            'plan_price' => $planPrice,
            'packages' => $packages->values()->all(),
            'addons' => $addons->values()->all(),
            'total' => $total,
        ];
    }
}