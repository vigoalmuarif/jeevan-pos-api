<?php

namespace Modules\Purchase\Transformers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class PurchasePaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        $payment = is_array($this->resource)
            ? $this->resource
            : ($this->resource instanceof Model ? $this->resource->toArray() : (array) $this->resource);
        $payment['invoices_count'] ??= count($payment['allocations'] ?? []);

        return Arr::only($payment, [
            'id',
            'document_number',
            'supplier_id',
            'supplier_name',
            'payment_date',
            'payment_method',
            'amount',
            'reference_number',
            'notes',
            'status',
            'voided_at',
            'void_reason',
            'created_at',
            'invoices_count',
            'allocations',
        ]);
    }
}
