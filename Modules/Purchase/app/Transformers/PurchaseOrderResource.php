<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'supplier_code' => $this->supplier?->code,
            'payment_term_days' => $this->supplier?->payment_term_days ?? 0,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'warehouse_code' => $this->warehouse?->code,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'status' => $this->status?->value,
            'is_tax_inclusive' => (bool) $this->is_tax_inclusive,
            'items_subtotal' => $this->items_subtotal,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'notes' => $this->notes,
            'approved_at' => $this->approved_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'purchase_requests' => collect($this->items ?? [])
                ->flatMap(fn ($item) => collect($item->requestLinks ?? [])->map(fn ($link) => $link->requestItem?->purchaseRequest))
                ->filter()
                ->unique('id')
                ->values()
                ->map(fn ($request) => [
                    'id' => $request->id,
                    'document_number' => $request->document_number,
                    'request_date' => $request->request_date?->toDateString(),
                ]),
            'items' => collect($this->items ?? [])->map(function ($item) {
                $rem = bcsub((string) $item->base_quantity, (string) $item->received_base_quantity, 4);
                $remainingUnitQty = bccomp((string) $item->conversion_rate, '0', 6) > 0
                    ? bcdiv($rem, (string) $item->conversion_rate, 4)
                    : $rem;

                return [
                    'id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name' => $item->productVariant?->product?->name,
                    'variant_name' => $item->productVariant?->name,
                    'sku' => $item->productVariant?->sku,
                    'unit_id' => $item->unit_id,
                    'unit_name' => $item->unit?->name,
                    'unit_code' => $item->unit?->code,
                    'unit_quantity' => $item->unit_quantity,
                    'conversion_rate' => $item->conversion_rate,
                    'base_quantity' => $item->base_quantity,
                    'received_base_quantity' => $item->received_base_quantity,
                    'remaining_base_quantity' => $rem,
                    'remaining_unit_quantity' => $remainingUnitQty,
                    'unit_price' => $item->unit_price,
                    'discount_type' => $item->discount_type?->value,
                    'discount_value' => $item->discount_value,
                    'discount_amount' => $item->discount_amount,
                    'tax_rate' => $item->tax_rate,
                    'tax_amount' => $item->tax_amount,
                    'line_total' => $item->line_total,
                    'notes' => $item->notes,
                ];
            })->values(),
            'goods_receipts' => collect($this->goodsReceipts ?? [])->map(fn ($gr) => [
                'id' => $gr->id,
                'document_number' => $gr->document_number,
                'status' => $gr->status?->value,
                'receipt_date' => $gr->receipt_date?->toDateString(),
            ])->values(),
        ];
    }
}
