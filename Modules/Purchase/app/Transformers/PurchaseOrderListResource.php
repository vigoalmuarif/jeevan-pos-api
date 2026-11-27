<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'supplier_code' => $this->supplier?->code,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'warehouse_code' => $this->warehouse?->code,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'status' => $this->status?->value,
            'items_count' => $this->items_count,
            'grand_total' => $this->grand_total,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
