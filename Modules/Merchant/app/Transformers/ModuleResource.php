<?php

namespace Modules\Merchant\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class ModuleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'code'          => $this->code,
            'name'          => $this->name,
            'description'   => $this->description,
            'type'          => $this->type,
            'category'      => $this->category,
            'icon'          => $this->icon,
            'is_toggleable' => $this->is_toggleable,
            'is_enabled'    => $this->is_enabled,
        ];
    }
}