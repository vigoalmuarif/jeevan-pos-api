<?php

declare(strict_types=1);

namespace Modules\Sales\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashierShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'shift_number' => (string) $this->shift_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'branch_id' => (int) $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => (int) $this->branch->id,
                'name' => (string) $this->branch->name,
            ] : null),
            'user_id' => (int) $this->user_id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => (int) $this->user->id,
                'name' => (string) $this->user->name,
            ] : null),
            'closed_by_user' => $this->whenLoaded('closedByUser', fn () => $this->closedByUser ? [
                'id' => (int) $this->closedByUser->id,
                'name' => (string) $this->closedByUser->name,
            ] : null),
            'opening_cash' => (float) $this->opening_cash,
            'expected_cash' => $this->expected_cash !== null ? (float) $this->expected_cash : null,
            'actual_cash' => $this->actual_cash !== null ? (float) $this->actual_cash : null,
            'variance' => $this->variance !== null ? (float) $this->variance : null,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'notes' => $this->notes,
            'movements' => CashierShiftMovementResource::collection($this->whenLoaded('movements')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
