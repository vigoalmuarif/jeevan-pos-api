<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch_name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse_name,
            'adjustment_date' => $this->adjustment_date,
            'reason' => $this->reason,
            'status' => $this->status,
            'notes' => $this->notes,
            'items_count' => $this->items_count,
            'created_at' => $this->created_at,
        ];
    }
}
