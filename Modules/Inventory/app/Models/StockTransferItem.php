<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\Unit;

/**
 * Item transfer. received_base_quantity, variance_base_quantity, dan unit_cost TIDAK fillable:
 * diisi StockTransferService saat kirim/terima, bukan dari request.
 */
class StockTransferItem extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = ['product_variant_id', 'unit_id', 'unit_quantity', 'conversion_rate', 'base_quantity', 'notes'];

    protected $attributes = ['variance_base_quantity' => 0];

    protected function casts(): array
    {
        return [
            'unit_quantity' => 'decimal:4',
            'conversion_rate' => 'decimal:6',
            'base_quantity' => 'decimal:4',
            'received_base_quantity' => 'decimal:4',
            'variance_base_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->with('product');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
