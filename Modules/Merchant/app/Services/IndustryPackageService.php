<?php

namespace Modules\Merchant\Services;

use Modules\Core\Abstracts\BaseService;
use Modules\Merchant\Models\{Merchant, IndustryPackage, MerchantIndustryPackage};
use Illuminate\Support\Facades\DB;

class IndustryPackageService extends BaseService
{
    public function __construct(private MerchantModuleResolver $moduleResolver)
    {
    }

    public function subscribe(Merchant $merchant, IndustryPackage $package, string $billingCycle = 'monthly'): MerchantIndustryPackage
    {
        $price = $package->currentPrice($billingCycle);

        if (!$price) {
            throw new \RuntimeException("Industry package {$package->code} tidak memiliki harga aktif untuk {$billingCycle}");
        }

        return DB::transaction(function () use ($merchant, $package, $billingCycle, $price) {
            $merchantPackage = MerchantIndustryPackage::updateOrCreate(
                ['merchant_id' => $merchant->id, 'industry_package_id' => $package->id],
                [
                    'is_active' => true,
                    'price_snapshot' => $price->price,
                    'currency_snapshot' => $price->currency,
                    'billing_cycle_snapshot' => $billingCycle,
                    'industry_package_price_id' => $price->id,
                    'activated_at' => now(),
                    'deactivated_at' => null,
                ]
            );

            $this->moduleResolver->syncModules($merchant);

            return $merchantPackage;
        });
    }

    public function unsubscribe(Merchant $merchant, IndustryPackage $package): void
    {
        DB::transaction(function () use ($merchant, $package) {
            MerchantIndustryPackage::where('merchant_id', $merchant->id)
                ->where('industry_package_id', $package->id)
                ->update(['is_active' => false, 'deactivated_at' => now()]);

            $this->moduleResolver->syncModules($merchant);
        });
    }
}