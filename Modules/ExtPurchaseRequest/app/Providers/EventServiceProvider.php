<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\ExtPurchaseRequest\Listeners\ReleasePurchaseRequestLinks;
use Modules\Purchase\Events\PurchaseOrderCancelled;
use Modules\Purchase\Events\PurchaseOrderDeleted;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        PurchaseOrderCancelled::class => [
            ReleasePurchaseRequestLinks::class,
        ],
        PurchaseOrderDeleted::class => [
            ReleasePurchaseRequestLinks::class,
        ],
    ];

    protected static $shouldDiscoverEvents = true;

    protected function configureEmailVerification(): void {}
}
