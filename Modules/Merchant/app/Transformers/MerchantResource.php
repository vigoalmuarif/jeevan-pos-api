<?php

namespace Modules\Merchant\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'ulid'         => $this->ulid,
            'name'         => $this->name,
            'slug'         => $this->slug,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'address'      => $this->address,
            'city'         => $this->city,
            'province'     => $this->province,
            'country'      => $this->country,
            'logo'         => $this->logo,
            'timezone'     => $this->timezone,
            'currency'     => $this->currency,
            'locale'       => $this->locale,
            'status'       => $this->status,
            'users_count'  => $this->whenCounted('users'),
            'branches_count' => $this->whenCounted('branches'),
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
        ];
    }
}