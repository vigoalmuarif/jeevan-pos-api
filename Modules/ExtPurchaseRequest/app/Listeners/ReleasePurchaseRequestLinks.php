<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Listeners;

use Modules\ExtPurchaseRequest\Services\PurchaseRequestOrderLinker;
use Modules\Purchase\Events\PurchaseOrderCancelled;
use Modules\Purchase\Events\PurchaseOrderDeleted;

class ReleasePurchaseRequestLinks
{
    public function __construct(
        private readonly PurchaseRequestOrderLinker $linker,
    ) {}

    public function handle(PurchaseOrderCancelled|PurchaseOrderDeleted $event): void
    {
        $this->linker->release($event->order);
    }
}
