<?php

namespace Modules\Auth\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthSubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->whenLoaded('activeSubscription',
                fn() => $this->activeSubscription->plan->name
            ),
            'status' => $this->whenLoaded('activeSubscription', 
                fn() => $this->activeSubscription->status
            ),
            'current_period_start' => $this->whenLoaded('activeSubscription', 
                fn() => $this->activeSubscription->current_period_start
            ),
            'current_period_end' => $this->whenLoaded('activeSubscription', 
                fn() => $this->activeSubscription->current_period_end
            ),
        ];
    }
}
