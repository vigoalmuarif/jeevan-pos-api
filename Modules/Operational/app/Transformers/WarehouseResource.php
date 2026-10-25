<?php

namespace Modules\Operational\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'code'       => $this->code,
            'type'       => $this->type,
            'address'    => $this->address,
            'phone'      => $this->phone,
            'is_main'    => $this->whenLoaded(
                'branches',
                fn () => $this->branches->contains(fn ($branch) => (bool) $branch->pivot?->is_primary),
                false
            ),
            'is_active'  => $this->is_active,
            'branches'   => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'is_primary' => (bool) $branch->pivot?->is_primary,
            ])),
            'regions' => $this->whenLoaded('regions', fn () => $this->regions->map(fn ($region) => [
                'id' => $region->id,
                'name' => $region->name,
                'code' => $region->code,
                'type' => $region->type,
            ])),
            'region_ids' => $this->whenLoaded(
                'regions',
                fn () => $this->regions->pluck('id')->map(fn ($id) => (int) $id)->all(),
                []
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}