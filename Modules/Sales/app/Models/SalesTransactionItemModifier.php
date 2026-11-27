<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;

class SalesTransactionItemModifier extends BaseModel
{
    use BelongsToMerchant;

    protected $table = 'sales_transaction_item_modifiers';

    protected function casts(): array
    {
        return [
            'price_adjustment' => 'decimal:2',
            'quantity' => 'integer',
            'subtotal' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SalesTransactionItem::class, 'sales_transaction_item_id');
    }
}
