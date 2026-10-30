<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferItemResource extends JsonResource
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
            'unit_name' => $this->unit?->name,
            'unit_code' => $this->unit?->code,
            'unit_quantity' => $this->unit_quantity,
            'conversion_rate' => $this->conversion_rate,
            'base_quantity' => $this->base_quantity,
            'received_base_quantity' => $this->received_base_quantity,
            'variance_base_quantity' => $this->variance_base_quantity,
            'unit_cost' => $this->unit_cost,
            'notes' => $this->notes,
        ];
    }
}
