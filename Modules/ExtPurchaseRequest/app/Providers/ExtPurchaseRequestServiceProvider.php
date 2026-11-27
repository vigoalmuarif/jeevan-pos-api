<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class ExtPurchaseRequestServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'ExtPurchaseRequest';

    protected string $nameLower = 'ext-purchase-request';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
