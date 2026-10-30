<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'from_warehouse_id' => $this->from_warehouse_id,
            'from_warehouse_name' => $this->fromWarehouse?->name,
            'to_warehouse_id' => $this->to_warehouse_id,
            'to_warehouse_name' => $this->toWarehouse?->name,
            'transfer_date' => $this->transfer_date,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'notes' => $this->notes,
            'receive_notes' => $this->receive_notes,
            'shipped_at' => $this->shipped_at,
            'shipped_by' => $this->shipped_by,
            'received_at' => $this->received_at,
            'received_by' => $this->received_by,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->cancelled_by,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => StockTransferItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
