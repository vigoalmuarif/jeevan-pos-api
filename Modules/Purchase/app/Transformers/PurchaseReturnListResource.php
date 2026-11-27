<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'return_date' => $this->return_date?->toDateString(),
            'status' => $this->status?->value ?? $this->status,
            'settlement_method' => $this->settlement_method?->value ?? $this->settlement_method,
            'reason_code' => $this->reason_code?->value ?? $this->reason_code,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'goods_receipt_id' => $this->goods_receipt_id,
            'goods_receipt_number' => $this->goodsReceipt?->document_number,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'items_count' => $this->items_count ?? $this->items?->count() ?? 0,
            'created_at' => $this->created_at,
        ];
    }
}

