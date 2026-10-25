<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'receipt_date' => $this->receipt_date,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'status' => $this->status?->value,
            'items_count' => $this->items_count,
            'grand_total' => $this->grand_total,
            'created_at' => $this->created_at,
        ];
    }
}
