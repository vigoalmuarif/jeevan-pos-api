<?php

namespace Modules\Permission\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'display_name' => $this->display_name,
            'description'  => $this->description,
            'group'        => $this->group,
            'is_custom'    => !is_null($this->merchant_id),
        ];
    }
}