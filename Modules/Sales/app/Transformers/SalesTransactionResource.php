<?php

declare(strict_types=1);

namespace Modules\Sales\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'transaction_number' => (string) $this->transaction_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'order_type' => $this->order_type?->value ?? 'retail',
            'order_type_label' => $this->order_type?->label() ?? 'Reguler',
            'price_list_id' => $this->price_list_id ? (int) $this->price_list_id : null,
            'sales_channel' => $this->sales_channel ?? null,
            'channel_order_ref' => $this->channel_order_ref ?? null,
            'price_list' => $this->whenLoaded('priceList', fn () => $this->priceList ? [
                'id' => (int) $this->priceList->id,
                'code' => $this->priceList->code,
                'name' => (string) $this->priceList->name,
            ] : null),
            'queue_number' => $this->queue_number,
            'dining_table_id' => $this->dining_table_id ? (int) $this->dining_table_id : null,
            'guest_count' => $this->guest_count !== null ? (int) $this->guest_count : null,
            'dining_table' => $this->whenLoaded('diningTable', fn () => $this->diningTable ? [
                'id' => (int) $this->diningTable->id,
                'code' => (string) $this->diningTable->code,
                'name' => (string) $this->diningTable->name,
            ] : null),
            'branch_id' => (int) $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => (int) $this->branch->id,
                'name' => (string) $this->branch->name,
            ] : null),
            'warehouse_id' => (int) $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? [
                'id' => (int) $this->warehouse->id,
                'name' => (string) $this->warehouse->name,
            ] : null),
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
            'subtotal' => (float) $this->subtotal,
            'discount_type' => $this->discount_type->value,
            'discount_value' => (float) $this->discount_value,
            'discount_amount' => (float) $this->discount_amount,
            'service_charge_rate' => (float) ($this->service_charge_rate ?? 0),
            'service_charge_amount' => (float) ($this->service_charge_amount ?? 0),
            'tax_amount' => (float) $this->tax_amount,
            'grand_total' => (float) $this->grand_total,
            'paid_amount' => (float) $this->paid_amount,
            'change_amount' => (float) $this->change_amount,
            'total_cogs' => (float) $this->total_cogs,
            'gross_profit' => round((float) $this->grand_total - (float) $this->total_cogs, 2),
            'hold_label' => $this->hold_label,
            'notes' => $this->notes,
            'sold_at' => $this->sold_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => (int) $this->creator->id,
                'name' => (string) $this->creator->name,
            ] : null),
            'voider' => $this->whenLoaded('voider', fn () => $this->voider ? [
                'id' => (int) $this->voider->id,
                'name' => (string) $this->voider->name,
            ] : null),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'product_variant_id' => (int) $item->product_variant_id,
                'product_variant_unit_id' => (int) $item->product_variant_unit_id,
                'product_name' => (string) $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'unit_name' => (string) $item->unit_name,
                'unit_code' => $item->unit_code,
                'conversion_value' => (float) $item->conversion_value,
                'quantity' => (float) $item->quantity,
                'base_quantity' => (float) $item->base_quantity,
                'unit_price' => (float) $item->unit_price,
                'modifiers_total' => (float) ($item->modifiers_total ?? 0),
                'unit_cost' => (float) $item->unit_cost,
                'total_cogs' => (float) $item->total_cogs,
                'discount_type' => $item->discount_type->value,
                'discount_value' => (float) $item->discount_value,
                'discount_amount' => (float) $item->discount_amount,
                'tax_rate' => (float) $item->tax_rate,
                'tax_amount' => (float) $item->tax_amount,
                'subtotal' => (float) $item->subtotal,
                'total' => (float) $item->total,
                'notes' => $item->notes,
                'course_number' => (int) ($item->course_number ?? 1),
                'is_held_for_kitchen' => (bool) ($item->is_held_for_kitchen ?? false),
                'sent_to_kitchen_qty' => (float) ($item->sent_to_kitchen_qty ?? 0),
                'modifiers' => $item->relationLoaded('modifiers')
                    ? $item->modifiers->map(fn ($mod) => [
                        'id' => (int) $mod->id,
                        'modifier_group_id' => $mod->modifier_group_id ? (int) $mod->modifier_group_id : null,
                        'modifier_option_id' => $mod->modifier_option_id ? (int) $mod->modifier_option_id : null,
                        'modifier_group_name' => (string) $mod->modifier_group_name,
                        'modifier_option_name' => (string) $mod->modifier_option_name,
                        'price_adjustment' => (float) $mod->price_adjustment,
                        'quantity' => (int) $mod->quantity,
                        'subtotal' => (float) $mod->subtotal,
                    ])->values()->all()
                    : [],
            ])->values()->all()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => (int) $payment->id,
                'payment_method' => $payment->payment_method->value,
                'payment_method_label' => $payment->payment_method->label(),
                'amount' => (float) $payment->amount,
                'reference_number' => $payment->reference_number,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
