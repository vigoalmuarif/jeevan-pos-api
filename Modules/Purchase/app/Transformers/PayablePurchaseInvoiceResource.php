<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class PayablePurchaseInvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        $invoice = is_array($this->resource)
            ? $this->resource
            : (array) $this->resource;

        return Arr::only($invoice, [
            'id',
            'document_number',
            'supplier_invoice_number',
            'invoice_date',
            'due_date',
            'grand_total',
            'paid_amount',
            'balance_amount',
            'payment_status',
            'is_overdue',
        ]);
    }
}
