<?php

namespace Modules\Operational\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray($request): array
    {
        $primaryBranch = null;
        $firstBranch = null;

        if ($this->relationLoaded('branches')) {
            $primaryBranch = $this->branches->first(fn ($b) => (bool) $b->pivot?->is_primary);
            $firstBranch = $this->branches->first();
        }

        $regionCount = $this->relationLoaded('regions') ? $this->regions->count() : 0;

        if ($primaryBranch) {
            $category = 'branch_primary';
            $categoryLabel = 'Gudang Utama';
        } elseif ($firstBranch) {
            $category = 'branch_secondary';
            $categoryLabel = 'Gudang Tambahan';
        } elseif ($regionCount > 1) {
            $category = 'central_multi_region';
            $categoryLabel = 'Pusat Distribusi (Multi-Regional)';
        } else {
            $category = 'independent_regional';
            $categoryLabel = 'Gudang Mandiri';
        }

        $assignedBranch = $primaryBranch ?? $firstBranch;

        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'code'       => $this->code,
            'type'       => $this->type,
            'address'    => $this->address,
            'phone'      => $this->phone,
            'category'   => $category,
            'category_label' => $categoryLabel,
            'branch_id'   => $assignedBranch?->id,
            'branch_name' => $assignedBranch?->name,
            'branch_code' => $assignedBranch?->code,
            'is_main'    => (bool) $primaryBranch,
            'is_primary' => (bool) $primaryBranch,
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