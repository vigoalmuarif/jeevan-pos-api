<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_variant_id' => $this->product_variant_id,
            'product_id' => $this->productVariant?->product_id,
            'product_name' => $this->productVariant?->product?->name,
            'variant_name' => $this->productVariant?->name,
            'sku' => $this->productVariant?->sku,
            'unit_id' => $this->unit_id,
            'unit_name' => $this->unit?->name ?? $this->unit?->code,
            'unit_code' => $this->unit?->code,
            'unit_quantity' => (float) $this->unit_quantity,
            'conversion_rate' => (float) $this->conversion_rate,
            'base_quantity' => (float) $this->base_quantity,
            'approved_base_quantity' => $this->approved_base_quantity !== null ? (float) $this->approved_base_quantity : null,
            'transferred_base_quantity' => (float) $this->transferred_base_quantity,
            'remaining_base_quantity' => max(((float) ($this->approved_base_quantity ?? $this->base_quantity)) - ((float) $this->transferred_base_quantity), 0.0),
            'notes' => $this->notes,
        ];
    }
}

