<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
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
            'supplier' => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'code' => $this->supplier->code,
                'phone' => $this->supplier->phone,
            ] : null,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'goods_receipt_id' => $this->goods_receipt_id,
            'goods_receipt' => $this->goodsReceipt ? [
                'id' => $this->goodsReceipt->id,
                'document_number' => $this->goodsReceipt->document_number,
                'receipt_date' => $this->goodsReceipt->receipt_date?->toDateString(),
                'created_at' => $this->goodsReceipt->created_at,
            ] : null,
            'purchase_invoice_id' => $this->purchase_invoice_id,
            'purchase_invoice_number' => $this->purchaseInvoice?->document_number,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at,
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => collect($this->items ?? [])->map(fn ($item) => [
                'id' => $item->id,
                'goods_receipt_item_id' => $item->goods_receipt_item_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->productVariant?->product?->name ?? 'Produk',
                'variant_name' => $item->productVariant?->name ?? '',
                'sku' => $item->productVariant?->sku ?? '',
                'unit_id' => $item->unit_id,
                'unit_name' => $item->unit?->name ?? '',
                'unit_code' => $item->unit?->code ?? '',
                'unit_quantity' => $item->unit_quantity,
                'conversion_rate' => $item->conversion_rate,
                'base_quantity' => $item->base_quantity,
                'unit_price' => $item->unit_price,
                'unit_cost' => $item->unit_cost,
                'tax_rate' => $item->tax_rate,
                'tax_amount' => $item->tax_amount,
                'subtotal' => $item->subtotal,
                'line_total' => $item->line_total,
                'reason' => $item->reason,
            ]),
        ];
    }
}

