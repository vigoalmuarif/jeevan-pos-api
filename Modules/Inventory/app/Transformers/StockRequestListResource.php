<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockRequestListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch_name ?? $this->branch?->name,
            'destination_warehouse_id' => $this->destination_warehouse_id,
            'destination_warehouse_name' => $this->destination_warehouse_name ?? $this->destinationWarehouse?->name,
            'source_warehouse_id' => $this->source_warehouse_id,
            'source_warehouse_name' => $this->source_warehouse_name ?? $this->sourceWarehouse?->name,
            'request_date' => $this->request_date,
            'expected_date' => $this->expected_date,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'items_count' => (int) ($this->items_count ?? 0),
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}

