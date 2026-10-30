<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
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
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'reject_reason' => $this->reject_reason,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancel_reason' => $this->cancel_reason,
            'closed_at' => $this->closed_at?->toISOString(),
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->productVariant?->product?->name,
                'variant_name' => $item->productVariant?->name,
                'sku' => $item->productVariant?->sku,
                'unit_id' => $item->unit_id,
                'unit_name' => $item->unit?->name,
                'unit_code' => $item->unit?->code,
                'conversion_rate' => (string) $item->conversion_rate,
                'unit_quantity' => (string) $item->unit_quantity,
                'base_quantity' => (string) $item->base_quantity,
                'ordered_base_quantity' => (string) $item->ordered_base_quantity,
                'remaining_base_quantity' => (string) $item->remaining_base_quantity,
                'on_hand_base_quantity' => (string) $item->on_hand_base_quantity,
                'suggested_supplier_id' => $item->suggested_supplier_id,
                'suggested_supplier_name' => $item->suggestedSupplier?->name,
                'estimated_unit_price' => $item->estimated_unit_price !== null ? (string) $item->estimated_unit_price : null,
                'notes' => $item->notes,
                'orders' => $item->orderLinks->map(fn ($link) => [
                    'order_id' => $link->orderItem?->purchase_order_id,
                    'document_number' => $link->orderItem?->purchaseOrder?->document_number,
                    'order_date' => $link->orderItem?->purchaseOrder?->order_date?->format('Y-m-d'),
                    'status' => $link->orderItem?->purchaseOrder?->status?->value ?? (string) $link->orderItem?->purchaseOrder?->status,
                    'base_quantity' => (string) $link->base_quantity,
                ]),
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
