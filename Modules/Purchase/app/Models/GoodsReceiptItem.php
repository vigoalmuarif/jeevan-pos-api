<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\Unit;
use Modules\Purchase\Enums\DiscountType;

/**
 * Item Goods Receipt.
 *
 * cost_amount dan unit_cost adalah HASIL PurchaseCalculator (harga setelah diskon item +
 * alokasi diskon header, plus pajak bila tax_in_cost). unit_cost (per SATUAN DASAR)
 * inilah yang dikirim ke StockService::recordMutation() sehingga memengaruhi HPP.
 * Nilai ini dibekukan (snapshot) saat dokumen dibuat dan tidak dihitung ulang saat dibaca.
 */
class GoodsReceiptItem extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'purchase_order_item_id', 'product_variant_id', 'unit_id',
        'unit_quantity', 'conversion_rate', 'base_quantity',
        'unit_price', 'discount_type', 'discount_value', 'discount_amount',
        'allocated_discount_amount', 'tax_rate', 'tax_amount', 'subtotal', 'line_total',
        'cost_amount', 'unit_cost', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_quantity' => 'decimal:4',
            'conversion_rate' => 'decimal:6',
            'base_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'allocated_discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'line_total' => 'decimal:4',
            'cost_amount' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** NULL bila pembelian langsung tanpa PO. */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
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