<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'request_date' => $this->request_date?->format('Y-m-d'),
            'required_date' => $this->required_date?->format('Y-m-d'),
            'source' => $this->source?->value ?? (string) $this->source,
            'status' => $this->status?->value ?? (string) $this->status,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'items_count' => (int) ($this->items_count ?? $this->items?->count() ?? 0),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
