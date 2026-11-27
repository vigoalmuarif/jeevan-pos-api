<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\DocumentStatus;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $poItemIds = collect($this->items ?? [])->pluck('id')->filter()->all();

        // Hitung kuantitas retur per baris item PO yang sudah diposting
        $returnedQuantities = empty($poItemIds)
            ? collect()
            : DB::table('purchase_return_items as pri')
                ->join('purchase_returns as pr', 'pr.id', '=', 'pri.purchase_return_id')
                ->join('goods_receipt_items as gri', 'gri.id', '=', 'pri.goods_receipt_item_id')
                ->whereIn('gri.purchase_order_item_id', $poItemIds)
                ->where('pr.status', DocumentStatus::Posted->value)
                ->whereNull('pr.deleted_at')
                ->groupBy('gri.purchase_order_item_id')
                ->selectRaw('gri.purchase_order_item_id, SUM(pri.unit_quantity) as total_returned_unit, SUM(pri.base_quantity) as total_returned_base')
                ->get()
                ->keyBy('purchase_order_item_id');

        $receiptIds = collect($this->goodsReceipts ?? [])->pluck('id')->filter()->all();
        $receiptReturns = empty($receiptIds)
            ? collect()
            : DB::table('purchase_returns')
                ->whereIn('goods_receipt_id', $receiptIds)
                ->where('status', DocumentStatus::Posted->value)
                ->whereNull('deleted_at')
                ->groupBy('goods_receipt_id')
                ->selectRaw('goods_receipt_id, COUNT(*) as return_count')
                ->pluck('return_count', 'goods_receipt_id');

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
            'has_returns' => $returnedQuantities->isNotEmpty(),
            'total_returned_items' => (float) $returnedQuantities->sum('total_returned_unit'),
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
            'items' => collect($this->items ?? [])->map(function ($item) use ($returnedQuantities) {
                $rem = bcsub((string) $item->base_quantity, (string) $item->received_base_quantity, 4);
                $remainingUnitQty = bccomp((string) $item->conversion_rate, '0', 6) > 0
                    ? bcdiv($rem, (string) $item->conversion_rate, 4)
                    : $rem;

                $retRow = $returnedQuantities->get($item->id);
                $returnedUnitQty = (float) ($retRow->total_returned_unit ?? 0);
                $returnedBaseQty = (float) ($retRow->total_returned_base ?? 0);
                $receivedBaseQty = (float) $item->received_base_quantity;
                $netReceivedBaseQty = max(0, $receivedBaseQty - $returnedBaseQty);
                $netReceivedUnitQty = bccomp((string) $item->conversion_rate, '0', 6) > 0
                    ? (float) bcdiv((string) $netReceivedBaseQty, (string) $item->conversion_rate, 4)
                    : $netReceivedBaseQty;

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
                    'returned_unit_quantity' => $returnedUnitQty,
                    'returned_base_quantity' => $returnedBaseQty,
                    'net_received_base_quantity' => $netReceivedBaseQty,
                    'net_received_unit_quantity' => $netReceivedUnitQty,
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
                'has_returns' => ($receiptReturns[$gr->id] ?? 0) > 0,
                'return_count' => (int) ($receiptReturns[$gr->id] ?? 0),
            ])->values(),
        ];
    }
}
