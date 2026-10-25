<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\Unit;
use Modules\Purchase\Enums\DiscountType;

/**
 * Item PO.
 *
 * received_base_quantity TIDAK fillable: nilainya hanya diubah PurchaseOrderService
 * saat goods receipt diposting / di-void, agar sisa penerimaan tidak bisa dimanipulasi
 * dari request.
 */
class PurchaseOrderItem extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'product_variant_id', 'unit_id',
        'unit_quantity', 'conversion_rate', 'base_quantity',
        'unit_price', 'discount_type', 'discount_value', 'discount_amount',
        'allocated_discount_amount', 'tax_rate', 'tax_amount', 'subtotal', 'line_total',
        'notes',
    ];

    protected $attributes = ['received_base_quantity' => 0];

    protected function casts(): array
    {
        return [
            'unit_quantity' => 'decimal:4',
            'conversion_rate' => 'decimal:6',
            'base_quantity' => 'decimal:4',
            'received_base_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'allocated_discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /**
     * Sisa qty (satuan dasar) yang belum diterima = dipesan - sudah diterima.
     * Dipakai untuk membatasi qty pada goods receipt berikutnya dan untuk menentukan
     * status PO (partially_received / received).
     * bcsub dipakai agar presisi desimal terjaga.
     */
    protected function remainingBaseQuantity(): Attribute
    {
        return Attribute::get(
            fn (): string => bcsub((string) $this->base_quantity, (string) $this->received_base_quantity, 4)
        );
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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