<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\Unit;

class StockRequestItem extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'product_variant_id',
        'unit_id',
        'unit_quantity',
        'conversion_rate',
        'base_quantity',
        'approved_base_quantity',
        'transferred_base_quantity',
        'notes',
    ];

    protected $attributes = [
        'transferred_base_quantity' => 0,
    ];

    protected function casts(): array
    {
        return [
            'unit_quantity' => 'decimal:4',
            'conversion_rate' => 'decimal:6',
            'base_quantity' => 'decimal:4',
            'approved_base_quantity' => 'decimal:4',
            'transferred_base_quantity' => 'decimal:4',
        ];
    }

    public function stockRequest(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class);
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

