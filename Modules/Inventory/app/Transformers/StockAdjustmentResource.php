<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'document_number' => data_get($this->resource, 'document_number'),
            'branch_id' => data_get($this->resource, 'branch_id'),
            'branch_name' => data_get($this->resource, 'branch_name'),
            'warehouse_id' => data_get($this->resource, 'warehouse_id'),
            'warehouse_name' => data_get($this->resource, 'warehouse_name'),
            'adjustment_date' => data_get($this->resource, 'adjustment_date'),
            'reason' => data_get($this->resource, 'reason'),
            'status' => data_get($this->resource, 'status'),
            'notes' => data_get($this->resource, 'notes'),
            'posted_at' => data_get($this->resource, 'posted_at'),
            'posted_by' => data_get($this->resource, 'posted_by'),
            'voided_at' => data_get($this->resource, 'voided_at'),
            'voided_by' => data_get($this->resource, 'voided_by'),
            'void_reason' => data_get($this->resource, 'void_reason'),
            'created_at' => data_get($this->resource, 'created_at'),
            'updated_at' => data_get($this->resource, 'updated_at'),
            'items' => collect(data_get($this->resource, 'items', []))
                ->map(fn ($item) => (new StockAdjustmentItemResource($item))->toArray($request))
                ->values(),
        ];
    }
}
