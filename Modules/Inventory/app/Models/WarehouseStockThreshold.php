<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Core\Traits\HasAudit;
use Modules\Operational\Models\Warehouse;
use Modules\Product\Models\ProductVariant;

/**
 * Batas stok minimum dan maksimum (satuan dasar) per gudang dan varian.
 * Sumber rekomendasi pembelian otomatis; dikelola di Inventory, dibaca Purchase.
 */
class WarehouseStockThreshold extends BaseModel
{
    use BelongsToMerchant, HasAudit;

    protected $fillable = ['warehouse_id', 'product_variant_id', 'min_quantity', 'max_quantity', 'is_active'];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:4',
            'max_quantity' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
