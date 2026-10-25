<?php

namespace Modules\Partner\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'customer_group_id' => $this->customer_group_id,
            'region_id' => $this->region_id,
            'credit_limit' => $this->credit_limit,
            'payment_term_days' => $this->payment_term_days,
            'is_active' => $this->is_active,
        ];
    }
}
