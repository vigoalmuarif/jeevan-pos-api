<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantUnit;

class SalesReturnItem extends BaseModel
{
    use BelongsToMerchant;

    protected $table = 'sales_return_items';

    protected function casts(): array
    {
        return [
            'conversion_value' => 'decimal:4',
            'quantity' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'total_cogs' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_refund' => 'decimal:2',
            'restock' => 'boolean',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function transactionItem(): BelongsTo
    {
        return $this->belongsTo(SalesTransactionItem::class, 'sales_transaction_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function variantUnit(): BelongsTo
    {
        return $this->belongsTo(ProductVariantUnit::class, 'product_variant_unit_id');
    }
}
