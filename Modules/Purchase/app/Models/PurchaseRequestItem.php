<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\Unit;
use Modules\Supplier\Models\Supplier;

/**
 * Item PR. ordered_base_quantity TIDAK fillable: hanya diubah PurchaseRequestService saat
 * PO dibuat / dihapus / dibatalkan, agar sisa permintaan tidak dapat dimanipulasi dari request.
 */
class PurchaseRequestItem extends BaseModel
{
    use BelongsToMerchant;

    protected $fillable = [
        'product_variant_id', 'unit_id',
        'unit_quantity', 'conversion_rate', 'base_quantity',
        'on_hand_base_quantity', 'suggested_supplier_id', 'estimated_unit_price', 'notes',
    ];

    protected $attributes = ['ordered_base_quantity' => 0];

    protected function casts(): array
    {
        return [
            'unit_quantity' => 'decimal:4',
            'conversion_rate' => 'decimal:6',
            'base_quantity' => 'decimal:4',
            'ordered_base_quantity' => 'decimal:4',
            'on_hand_base_quantity' => 'decimal:4',
            'estimated_unit_price' => 'decimal:4',
        ];
    }

    /**
     * Sisa qty (satuan dasar) yang belum dijadikan PO. Batas qty saat konversi, dan bagian
     * "sudah diminta" pada perhitungan rekomendasi stok minimum.
     */
    protected function remainingBaseQuantity(): Attribute
    {
        return Attribute::get(
            fn (): string => bcsub((string) $this->base_quantity, (string) $this->ordered_base_quantity, 4)
        );
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function suggestedSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'suggested_supplier_id');
    }

    public function orderLinks(): HasMany
    {
        return $this->hasMany(PurchaseRequestOrderLink::class);
    }
}