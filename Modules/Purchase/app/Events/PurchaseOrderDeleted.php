<?php

declare(strict_types=1);

namespace Modules\Purchase\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Purchase\Models\PurchaseOrder;

class PurchaseOrderDeleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly PurchaseOrder $order,
    ) {}
}
