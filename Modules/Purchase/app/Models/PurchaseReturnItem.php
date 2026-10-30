<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\Unit;

/**
 * Item Purchase Return (DEC-10).
 */
class PurchaseReturnItem extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'purchase_return_id', 'goods_receipt_item_id', 'product_variant_id', 'unit_id',
        'unit_quantity', 'conversion_rate', 'base_quantity',
        'unit_price', 'unit_cost', 'subtotal', 'tax_rate', 'tax_amount', 'line_total',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'unit_quantity' => 'decimal:4',
            'conversion_rate' => 'decimal:6',
            'base_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}

