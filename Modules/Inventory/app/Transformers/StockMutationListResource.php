<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMutationListResource extends JsonResource
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
            'mutation_type' => $this->mutation_type,
            'quantity' => $this->quantity,
            'quantity_before' => $this->quantity_before,
            'quantity_after' => $this->quantity_after,
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,
            'avg_cost_after' => $this->avg_cost_after,
            'reference_type' => $this->reference_type,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'mutated_at' => $this->mutated_at,
        ];
    }
}
