<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\DocumentStatus;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Hitung kuantitas retur per item yang sudah diposting
        $returnedQuantities = DB::table('purchase_return_items as pri')
            ->join('purchase_returns as pr', 'pr.id', '=', 'pri.purchase_return_id')
            ->where('pr.goods_receipt_id', $this->id)
            ->where('pr.status', DocumentStatus::Posted->value)
            ->whereNull('pr.deleted_at')
            ->groupBy('pri.goods_receipt_item_id')
            ->selectRaw('pri.goods_receipt_item_id, SUM(pri.unit_quantity) as total_returned')
            ->pluck('total_returned', 'goods_receipt_item_id');

        $totalReturned = 0;
        $remainingReturnable = 0;

        $items = collect($this->items ?? [])->map(function ($item) use ($returnedQuantities, &$totalReturned, &$remainingReturnable) {
            $returnedQty = (float) ($returnedQuantities[$item->id] ?? 0);
            $unitQty = (float) $item->unit_quantity;
            $netQty = max(0, $unitQty - $returnedQty);

            $totalReturned += $returnedQty;
            $remainingReturnable += $netQty;

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
                'returned_quantity' => $returnedQty,
                'net_quantity' => $netQty,
                'unit_price' => $item->unit_price,
                'discount_type' => $item->discount_type?->value,
                'discount_value' => $item->discount_value,
                'discount_amount' => $item->discount_amount,
                'tax_rate' => $item->tax_rate,
                'tax_amount' => $item->tax_amount,
                'line_total' => $item->line_total,
                'unit_cost' => $item->unit_cost,
                'notes' => $item->notes,
            ];
        })->values();

        $returns = collect($this->purchaseReturns ?? [])->map(fn ($r) => [
            'id' => $r->id,
            'document_number' => $r->document_number,
            'return_date' => $r->return_date?->toDateString(),
            'status' => $r->status?->value ?? $r->status,
            'settlement_method' => $r->settlement_method?->value ?? $r->settlement_method,
            'reason_code' => $r->reason_code?->value ?? $r->reason_code,
            'grand_total' => $r->grand_total,
            'items' => collect($r->items ?? [])->map(fn ($ri) => [
                'id' => $ri->id,
                'goods_receipt_item_id' => $ri->goods_receipt_item_id,
                'product_name' => $ri->productVariant?->product?->name,
                'variant_name' => $ri->productVariant?->name,
                'sku' => $ri->productVariant?->sku,
                'unit_name' => $ri->unit?->name,
                'unit_code' => $ri->unit?->code,
                'unit_quantity' => $ri->unit_quantity,
                'unit_price' => $ri->unit_price,
                'line_total' => $ri->line_total,
                'reason' => $ri->reason,
            ])->values(),
        ])->values();

        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->purchaseOrder ? [
                'id' => $this->purchaseOrder->id,
                'document_number' => $this->purchaseOrder->document_number,
                'order_date' => $this->purchaseOrder->order_date?->toDateString(),
            ] : null,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'delivery_note_number' => $this->delivery_note_number,
            'status' => $this->status?->value,
            'is_tax_inclusive' => (bool) $this->is_tax_inclusive,
            'tax_in_cost' => (bool) $this->tax_in_cost,
            'items_subtotal' => $this->items_subtotal,
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at,
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'has_returns' => $returns->isNotEmpty(),
            'total_returned_quantity' => $totalReturned,
            'remaining_returnable_quantity' => $remainingReturnable,
            'returns' => $returns,
            'items' => $items,
        ];
    }
}
