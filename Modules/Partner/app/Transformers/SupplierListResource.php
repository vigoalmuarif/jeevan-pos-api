<?php

namespace Modules\Partner\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierListResource extends JsonResource
{
  
     public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'code'              => $this->code,
            'name'              => $this->name,
            'contact_person'    => $this->contact_person,
            'phone'             => $this->phone,
            'payment_term_days' => $this->payment_term_days,
            'is_active'         => $this->is_active,
        ];
    }
}
