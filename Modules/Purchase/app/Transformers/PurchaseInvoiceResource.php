<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class PurchaseInvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        $invoice = is_array($this->resource)
            ? $this->resource
            : ($this->resource instanceof Model ? $this->resource->toArray() : (array) $this->resource);

        return Arr::only($invoice, [
            'id',
            'document_number',
            'supplier_id',
            'supplier_name',
            'branch_id',
            'supplier_invoice_number',
            'invoice_date',
            'due_date',
            'status',
            'payment_status',
            'is_overdue',
            'items_subtotal',
            'discount_amount',
            'tax_amount',
            'rounding_amount',
            'grand_total',
            'paid_amount',
            'balance_amount',
            'notes',
            'posted_at',
            'voided_at',
            'void_reason',
            'created_at',
            'updated_at',
            'receipts',
            'items',
            'payments',
        ]);
    }
}
