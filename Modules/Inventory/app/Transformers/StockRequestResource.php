<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'destination_warehouse_id' => $this->destination_warehouse_id,
            'destination_warehouse_name' => $this->destinationWarehouse?->name,
            'source_warehouse_id' => $this->source_warehouse_id,
            'source_warehouse_name' => $this->sourceWarehouse?->name,
            'request_date' => $this->request_date,
            'expected_date' => $this->expected_date,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at,
            'submitted_by' => $this->submitted_by,
            'submitted_by_name' => $this->submitter?->name,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->approver?->name,
            'rejected_at' => $this->rejected_at,
            'rejected_by' => $this->rejected_by,
            'rejected_by_name' => $this->rejecter?->name,
            'reject_reason' => $this->reject_reason,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->cancelled_by,
            'cancelled_by_name' => $this->canceller?->name,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'creator_name' => $this->creator?->name,
            'items' => StockRequestItemResource::collection($this->whenLoaded('items')),
            'transfers' => $this->whenLoaded('transfers', fn () => $this->transfers->map(fn ($t) => [
                'id' => $t->id,
                'document_number' => $t->document_number,
                'status' => $t->status instanceof \BackedEnum ? $t->status->value : $t->status,
                'transfer_date' => $t->transfer_date,
            ])),
        ];
    }
}

