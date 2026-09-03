<?php
// Modules/Merchant/Services/AddonService.php

namespace Modules\Merchant\Services;

use Modules\Core\Abstracts\BaseService;
use Modules\Merchant\Models\{Merchant, Addon, MerchantAddon};

class AddonService extends BaseService
{
    public function __construct(private MerchantModuleResolver $moduleResolver)
    {
    }

    public function purchase(Merchant $merchant, Addon $addon, int $quantity = 1, string $billingCycle = 'monthly'): MerchantAddon
    {
        $price = $addon->currentPrice($billingCycle);

        if (!$price) {
            throw new \RuntimeException("Addon {$addon->code} tidak memiliki harga aktif untuk {$billingCycle}");
        }

        if ($addon->isModuleType()) {
            $quantity = 1;

            $alreadyActive = MerchantAddon::where('merchant_id', $merchant->id)
                ->where('addon_id', $addon->id)
                ->where('is_active', true)
                ->exists();

            if ($alreadyActive) {
                throw new \RuntimeException("Addon module {$addon->code} sudah aktif untuk merchant ini");
            }
        }

        if ($addon->isQuantityType() && $quantity < 1) {
            throw new \InvalidArgumentException('Quantity minimal 1');
        }

        $merchantAddon = MerchantAddon::create([
            'merchant_id' => $merchant->id,
            'addon_id' => $addon->id,
            'quantity' => $quantity,
            'is_active' => true,
            'price_snapshot' => $price->price,
            'currency_snapshot' => $price->currency,
            'billing_cycle_snapshot' => $billingCycle,
            'addon_price_id' => $price->id,
            'activated_at' => now(),
        ]);

        if ($addon->isModuleType()) {
            $this->moduleResolver->syncModules($merchant);
        }

        return $merchantAddon;
    }

    public function deactivate(Merchant $merchant, MerchantAddon $merchantAddon): void
    {
        $merchantAddon->update(['is_active' => false, 'deactivated_at' => now()]);

        if ($merchantAddon->addon->isModuleType()) {
            $this->moduleResolver->syncModules($merchant);
        }
    }

    /**
     * Total extra limit dari addon quantity-based untuk satu limit_key
     */
    public function getExtraLimit(Merchant $merchant, string $limitKey): int
    {
        return MerchantAddon::query()
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->whereHas('addon', fn($q) => $q->where('target_limit_key', $limitKey)->whereIn('type', ['per_unit', 'bundle']))
            ->get()
            ->sum(function (MerchantAddon $ma) {
                $addon = $ma->addon;

                return $addon->type === 'bundle'
                    ? $ma->quantity * $addon->bundle_quantity
                    : $ma->quantity;
            });
    }
}