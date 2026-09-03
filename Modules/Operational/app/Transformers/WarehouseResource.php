<?php

namespace Modules\Operational\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'branch_id'  => $this->branch_id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'code'       => $this->code,
            'type'       => $this->type,
            'address'    => $this->address,
            'phone'      => $this->phone,
            'is_main'    => $this->is_main,
            'is_active'  => $this->is_active,
            'regions'   => $this->whenLoaded('regions'),
            'branches'   => $this->whenLoaded('branches'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}