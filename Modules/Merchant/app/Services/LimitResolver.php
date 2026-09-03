<?php

namespace Modules\Merchant\Services;

use Modules\Core\Abstracts\BaseService;
use Modules\Merchant\Models\Merchant;

class LimitResolver extends BaseService
{
    public function __construct(private AddonService $addonService) {}

    public function getEffectiveLimit(Merchant $merchant, string $limitKey): int
    {
        $baseLimit = $merchant->activeSubscription->plan->getLimit($limitKey);

        if ($baseLimit === -1) {
            return -1; // unlimited, addon tidak relevan
        }

        $extra = $this->addonService->getExtraLimit($merchant, $limitKey);

        return $baseLimit + $extra;
    }
}
