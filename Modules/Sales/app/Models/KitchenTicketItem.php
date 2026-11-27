<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class KitchenTicketItem extends BaseModel
{
    use BelongsToMerchant;

    protected $table = 'kitchen_ticket_items';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'modifiers_snapshot' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class, 'kitchen_ticket_id');
    }
}
