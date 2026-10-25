<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class InvoiceableGoodsReceiptResource extends JsonResource
{
    public function toArray($request): array
    {
        $receipt = is_array($this->resource)
            ? $this->resource
            : (array) $this->resource;

        return Arr::only($receipt, [
            'id',
            'document_number',
            'receipt_date',
            'delivery_note_number',
            'warehouse_name',
            'items_subtotal',
            'discount_amount',
            'tax_amount',
            'grand_total',
        ]);
    }
}
