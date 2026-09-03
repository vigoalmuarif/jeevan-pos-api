<?php

namespace Modules\User\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Operational\Transformers\BranchResource;
use Modules\Merchant\Transformers\MerchantResource;
use Modules\Permission\Transformers\RoleResource;

class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'username'         => $this->username,
            'email'            => $this->email,
            'phone'            => $this->phone,
            'avatar'           => $this->avatar,
            'is_active'           => $this->is_active,
            'is_all_branches'  => $this->is_all_branches,
            'merchant'         => $this->whenLoaded('merchant',
                fn() => new MerchantResource($this->merchant)
            ),
            'role'             => $this->whenLoaded('roles',
                fn() => new RoleResource($this->roles->first())
            ),
            'active_branch'    => $this->is_all_branch 
                                ? $this->whenLoaded('merchant.mainBranch',
                                    fn() => new BranchResource($this->merchant->main_branch))
                                : $this->whenLoaded('currentActiveBranch',
                                    fn() => new BranchResource($this->current_active_branch)),
            'last_login_at'    => $this->last_login_at,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}