<?php

namespace Modules\Core\Jobs\Middleware;

use Closure;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class EnsureMerchantContext
{
    public function __construct(
        protected int $merchantId
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        $merchant = Merchant::find($this->merchantId);

        if (!$merchant) {
            throw new RuntimeException("Merchant [ID: {$this->merchantId}] not found for queue job.");
        }

        if (in_array($merchant->status, ['inactive', 'suspended'], true)) {
            throw new RuntimeException("Merchant [ID: {$this->merchantId}] is inactive or suspended. Job execution aborted.");
        }

        MerchantContext::set($merchant);

        if (function_exists('app') && app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($merchant->id);
        }

        try {
            return $next($job);
        } finally {
            MerchantContext::clear();
        }
    }
}

