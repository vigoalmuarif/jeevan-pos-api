<?php

namespace Modules\Partner\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */

    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'code'                => $this->code,
            'name'                => $this->name,
            'contact_person'      => $this->contact_person,
            'phone'               => $this->phone,
            'email'               => $this->email,
            'tax_id'              => $this->tax_id,
            'address'             => $this->address,
            'region_code'         => $this->region_code,
            'postal_code'         => $this->postal_code,
            'payment_term_days'   => $this->payment_term_days,
            'bank_name'           => $this->bank_name,
            'bank_account_name'   => $this->bank_account_name,
            'bank_account_number' => $this->bank_account_number,
            'notes'               => $this->notes,
            'is_active'           => $this->is_active,
            'created_at'          => $this->created_at?->toIso8601String(),
            'updated_at'          => $this->updated_at?->toIso8601String(),

            // Opsional: hanya ada bila relasi dimuat.
            'region'              => $this->whenLoaded(
                'region',
                fn($region) => ['kode' => $region->kode, 'nama' => $region->nama],
            ),
        ];
    }
}
