<?php

declare(strict_types=1);

namespace Modules\Sales\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Enums\ReturnFeeBearer;
use Modules\Sales\Enums\SalesPaymentMethod;
use Modules\Sales\Enums\SalesReturnStatus;
use Modules\Sales\Enums\SalesReturnType;

class SalesReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof SalesReturnStatus
            ? $this->status
            : SalesReturnStatus::tryFrom((string) $this->status);

        $returnType = $this->return_type instanceof SalesReturnType
            ? $this->return_type
            : (SalesReturnType::tryFrom((string) ($this->return_type ?? 'refund')) ?? SalesReturnType::Refund);

        $refundMethod = $this->refund_method instanceof SalesPaymentMethod
            ? $this->refund_method
            : SalesPaymentMethod::tryFrom((string) $this->refund_method);

        $feeBearer = $this->return_fee_bearer instanceof ReturnFeeBearer
            ? $this->return_fee_bearer
            : (ReturnFeeBearer::tryFrom((string) ($this->return_fee_bearer ?? 'customer')) ?? ReturnFeeBearer::Customer);

        return [
            'id' => (int) $this->id,
            'return_number' => (string) $this->return_number,
            'status' => $status?->value ?? (string) $this->status,
            'status_label' => $status?->label() ?? (string) $this->status,
            'return_type' => $returnType->value,
            'return_type_label' => $returnType->label(),
            'refund_method' => $refundMethod?->value ?? (string) $this->refund_method,
            'refund_method_label' => $refundMethod?->label() ?? (string) $this->refund_method,
            'reason' => (string) $this->reason,
            'branch_id' => (int) $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => (int) $this->branch->id,
                'name' => (string) $this->branch->name,
            ]),
            'warehouse_id' => (int) $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => (int) $this->warehouse->id,
                'name' => (string) $this->warehouse->name,
            ]),
            'sales_transaction_id' => (int) $this->sales_transaction_id,
            'transaction' => $this->whenLoaded('transaction', fn () => [
                'id' => (int) $this->transaction->id,
                'transaction_number' => (string) $this->transaction->transaction_number,
                'grand_total' => round((float) $this->transaction->grand_total, 2),
                'sold_at' => $this->transaction->sold_at?->toISOString(),
            ]),
            'cashier_shift_id' => $this->cashier_shift_id ? (int) $this->cashier_shift_id : null,
            'cashier_shift' => $this->whenLoaded('cashierShift', fn () => $this->cashierShift ? [
                'id' => (int) $this->cashierShift->id,
                'shift_number' => (string) $this->cashierShift->shift_number,
            ] : null),
            'customer_id' => $this->customer_id ? (int) $this->customer_id : null,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => (int) $this->customer->id,
                'code' => $this->customer->code,
                'name' => (string) $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null),
            'subtotal' => round((float) $this->subtotal, 2),
            'discount_amount' => round((float) $this->discount_amount, 2),
            'tax_amount' => round((float) $this->tax_amount, 2),
            'total_refund' => round((float) $this->total_refund, 2),
            'exchange_total' => round((float) ($this->exchange_total ?? 0), 2),
            'return_fee' => round((float) ($this->return_fee ?? 0), 2),
            'return_fee_bearer' => $feeBearer->value,
            'return_fee_bearer_label' => $feeBearer->label(),
            'settlement_amount' => round((float) ($this->settlement_amount ?? (-1 * (float) $this->total_refund)), 2),
            'total_cogs_reversed' => round((float) $this->total_cogs_reversed, 2),
            'total_cogs_exchange' => round((float) ($this->total_cogs_exchange ?? 0), 2),
            'items_count' => $this->when(isset($this->items_count), fn () => (int) $this->items_count),
            'exchange_items_count' => $this->when(isset($this->exchange_items_count), fn () => (int) $this->exchange_items_count),
            'returned_at' => $this->returned_at?->toISOString(),
            'voided_at' => $this->voided_at?->toISOString(),
            'void_reason' => $this->void_reason,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => (int) $this->creator->id,
                'name' => (string) $this->creator->name,
            ] : null),
            'voider' => $this->whenLoaded('voider', fn () => $this->voider ? [
                'id' => (int) $this->voider->id,
                'name' => (string) $this->voider->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'sales_transaction_item_id' => (int) $item->sales_transaction_item_id,
                'product_id' => (int) $item->product_id,
                'product_variant_id' => (int) $item->product_variant_id,
                'product_variant_unit_id' => (int) $item->product_variant_unit_id,
                'product_name' => (string) $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'unit_name' => (string) $item->unit_name,
                'unit_code' => $item->unit_code,
                'conversion_value' => round((float) $item->conversion_value, 4),
                'quantity' => round((float) $item->quantity, 4),
                'base_quantity' => round((float) $item->base_quantity, 4),
                'unit_price' => round((float) $item->unit_price, 2),
                'unit_cost' => round((float) $item->unit_cost, 4),
                'total_cogs' => round((float) $item->total_cogs, 2),
                'discount_amount' => round((float) $item->discount_amount, 2),
                'tax_amount' => round((float) $item->tax_amount, 2),
                'subtotal' => round((float) $item->subtotal, 2),
                'total_refund' => round((float) $item->total_refund, 2),
                'restock' => (bool) $item->restock,
                'notes' => $item->notes,
            ])->values()->all()),
            'exchange_items' => $this->whenLoaded('exchangeItems', fn () => $this->exchangeItems->map(fn ($ex) => [
                'id' => (int) $ex->id,
                'product_id' => (int) $ex->product_id,
                'product_variant_id' => (int) $ex->product_variant_id,
                'product_variant_unit_id' => (int) $ex->product_variant_unit_id,
                'product_name' => (string) $ex->product_name,
                'variant_name' => $ex->variant_name,
                'sku' => $ex->sku,
                'unit_name' => (string) $ex->unit_name,
                'unit_code' => $ex->unit_code,
                'conversion_value' => round((float) $ex->conversion_value, 4),
                'quantity' => round((float) $ex->quantity, 4),
                'base_quantity' => round((float) $ex->base_quantity, 4),
                'unit_price' => round((float) $ex->unit_price, 2),
                'unit_cost' => round((float) $ex->unit_cost, 4),
                'total_cogs' => round((float) $ex->total_cogs, 2),
                'subtotal' => round((float) $ex->subtotal, 2),
                'total' => round((float) $ex->total, 2),
                'notes' => $ex->notes,
            ])->values()->all()),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
