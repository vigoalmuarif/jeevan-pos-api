<?php

namespace Modules\Inventory\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'product_id' => data_get($this->resource, 'product_id'),
            'product_name' => data_get($this->resource, 'product_name'),
            'product_variant_id' => data_get($this->resource, 'product_variant_id'),
            'variant_name' => data_get($this->resource, 'variant_name'),
            'sku' => data_get($this->resource, 'sku'),
            'unit_id' => data_get($this->resource, 'unit_id'),
            'unit_name' => data_get($this->resource, 'unit_name'),
            'unit_code' => data_get($this->resource, 'unit_code'),
            'unit_quantity' => data_get($this->resource, 'unit_quantity'),
            'conversion_rate' => data_get($this->resource, 'conversion_rate'),
            'base_quantity' => data_get($this->resource, 'base_quantity'),
            'unit_cost' => data_get($this->resource, 'unit_cost'),
            'notes' => data_get($this->resource, 'notes'),
        ];
    }
}
