<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'delivery_note_number' => $this->delivery_note_number,
            'status' => $this->status?->value,
            'is_tax_inclusive' => (bool) $this->is_tax_inclusive,
            'tax_in_cost' => (bool) $this->tax_in_cost,
            'items_subtotal' => $this->items_subtotal,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at,
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => collect($this->items ?? [])->map(fn ($item) => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->productVariant?->product?->name,
                'variant_name' => $item->productVariant?->name,
                'sku' => $item->productVariant?->sku,
                'unit_id' => $item->unit_id,
                'unit_name' => $item->unit?->name,
                'unit_code' => $item->unit?->code,
                'unit_quantity' => $item->unit_quantity,
                'conversion_rate' => $item->conversion_rate,
                'base_quantity' => $item->base_quantity,
                'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type?->value,
                'discount_value' => $item->discount_value,
                'discount_amount' => $item->discount_amount,
                'tax_rate' => $item->tax_rate,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
                'unit_cost' => $item->unit_cost,
                'notes' => $item->notes,
            ])->values(),
        ];
    }
}
