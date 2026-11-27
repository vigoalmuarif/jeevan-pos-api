<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantUnit;
use Modules\Sales\Enums\SalesDiscountType;

class SalesTransactionItem extends BaseModel
{
    use BelongsToMerchant;

    protected $table = 'sales_transaction_items';

    protected function casts(): array
    {
        return [
            'discount_type' => SalesDiscountType::class,
            'conversion_value' => 'decimal:4',
            'quantity' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'modifiers_total' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'total_cogs' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class, 'sales_transaction_id');
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

    public function modifiers(): HasMany
    {
        return $this->hasMany(SalesTransactionItemModifier::class, 'sales_transaction_item_id')
            ->orderBy('id');
    }
}
