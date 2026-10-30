<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'from_warehouse_id' => $this->from_warehouse_id,
            'from_warehouse_name' => $this->from_warehouse_name,
            'to_warehouse_id' => $this->to_warehouse_id,
            'to_warehouse_name' => $this->to_warehouse_name,
            'transfer_date' => $this->transfer_date,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'items_count' => $this->items_count,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
