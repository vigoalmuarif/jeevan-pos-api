<?php

declare(strict_types=1);

namespace Modules\Product\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModifierGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'selection_type' => (string) $this->selection_type,
            'is_required' => (bool) $this->is_required,
            'min_select' => (int) $this->min_select,
            'max_select' => $this->max_select !== null ? (int) $this->max_select : null,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'products_count' => $this->whenCounted('products'),
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn ($opt) => [
                'id' => (int) $opt->id,
                'name' => (string) $opt->name,
                'price_adjustment' => (float) $opt->price_adjustment,
                'is_default' => (bool) $opt->is_default,
                'sort_order' => (int) $opt->sort_order,
                'is_active' => (bool) $opt->is_active,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
