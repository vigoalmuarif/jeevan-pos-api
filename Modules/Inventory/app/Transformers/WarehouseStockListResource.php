<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseStockListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_variant_id' => $this->product_variant_id,
            'variant_name' => $this->variant_name,
            'sku' => $this->sku,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch_name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse_name,
            'quantity' => $this->quantity,
            'avg_cost' => $this->avg_cost,
            'last_mutated_at' => $this->last_mutated_at,
        ];
    }
}
