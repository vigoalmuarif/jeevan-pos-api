<?php

declare(strict_types=1);

namespace Modules\Sales\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashierShiftMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'cashier_shift_id' => (int) $this->cashier_shift_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'amount' => (float) $this->amount,
            'reason' => (string) $this->reason,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => (int) $this->creator->id,
                'name' => (string) $this->creator->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
