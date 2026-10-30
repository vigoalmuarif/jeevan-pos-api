<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Purchase\Models\PurchaseOrderItem;

/**
 * Penghubung item PR -> item PO. Dibuat dan dihapus HANYA oleh PurchaseRequestService
 * bersamaan dengan pembaruan purchase_request_items.ordered_base_quantity (satu transaksi).
 */
class PurchaseRequestOrderLink extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'purchase_request_item_id',
        'purchase_order_item_id',
        'base_quantity',
    ];

    protected function casts(): array
    {
        return [
            'base_quantity' => 'decimal:4',
        ];
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }
}
