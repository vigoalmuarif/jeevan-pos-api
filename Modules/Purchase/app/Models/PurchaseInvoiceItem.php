<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\Unit;

/**
 * Baris faktur = SNAPSHOT nilai tagihan yang disalin dari goods_receipt_items saat faktur
 * dibuat. Sengaja tidak dihitung ulang dari GR, agar faktur tetap utuh sebagai dokumen
 * akuntansi walaupun data lain berubah.
 */
class PurchaseInvoiceItem extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'goods_receipt_id', 'goods_receipt_item_id', 'product_variant_id', 'unit_id',
        'unit_quantity', 'unit_price', 'discount_amount', 'allocated_discount_amount',
        'tax_rate', 'tax_amount', 'subtotal', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'allocated_discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
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