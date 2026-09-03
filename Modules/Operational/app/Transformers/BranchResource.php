<?php

namespace Modules\Operational\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'slug' => $this->slug,
            'email' => $this->email,
            'phone' => $this->phone,
            'receipt_header' => $this->receipt_header,
            'receipt_footer' => $this->receipt_footer,
            'is_main' => $this->is_main,
            'is_active' => $this->is_active,
            'region' => $this->whenLoaded('region'),
            'address' => [
                'street' => $this->address,
                'postal_code' => $this->postal_code,
                'village' => $this->whenLoaded('village'),
                'district' => $this->whenLoaded('district'),
                'regency' => $this->whenLoaded('regency'),
                'province' => $this->whenLoaded('province'),
            ],
            'warehouses_count' => $this->whenCounted('warehouses'),
            'main_warehouse' => $this->whenLoaded('mainWarehouse'),
            'warehouses' => $this->whenLoaded(
                'warehouses',
                fn() => WarehouseResource::collection($this->warehouses)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}