<?php

namespace Modules\Product\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ] : null),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'is_primary' => (bool) $category->pivot->is_primary,
            ])),
            'suppliers' => $this->whenLoaded('suppliers', fn () => $this->suppliers->map(fn ($supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
            ])),
            'track_stock' => (bool) $this->track_stock,
            'is_taxable' => (bool) $this->is_taxable,
            'tax_rate' => $this->tax_rate,
            'min_stock' => $this->min_stock,
            'weight' => $this->weight,
            'is_active' => (bool) $this->is_active,
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => Storage::disk('public')->url($image->path),
                'original_name' => $image->original_name,
                'sort_order' => $image->sort_order,
            ])),
            'units' => $this->whenLoaded('unitConversions', fn () => $this->unitConversions->map(fn ($conversion) => [
                'unit' => [
                    'id' => $conversion->unit->id,
                    'name' => $conversion->unit->name,
                    'code' => $conversion->unit->code,
                ],
                'conversion_value' => $conversion->conversion_value,
                'is_base' => (bool) $conversion->is_base,
            ])),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'is_default' => (bool) $variant->is_default,
                'is_active' => (bool) $variant->is_active,
                'images' => $variant->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => Storage::disk('public')->url($image->path),
                    'original_name' => $image->original_name,
                    'sort_order' => $image->sort_order,
                ]),
                'units' => $variant->units->map(fn ($variantUnit) => [
                    'id' => $variantUnit->id,
                    'unit' => [
                        'id' => $variantUnit->unit->id,
                        'name' => $variantUnit->unit->name,
                        'code' => $variantUnit->unit->code,
                    ],
                    'conversion_value' => $variantUnit->conversion_value,
                    'is_base' => (bool) $variantUnit->is_base,
                    'barcode' => $variantUnit->barcode,
                    'cost' => $variantUnit->cost,
                    'default_price' => $variantUnit->default_price,
                    'prices' => $variantUnit->prices->map(fn ($price) => [
                        'id' => $price->id,
                        'price_list_id' => $price->price_list_id,
                        'branch_id' => $price->branch_id,
                        'min_qty' => $price->min_qty,
                        'price' => $price->price,
                        'pricing_mode' => $price->pricing_mode,
                        'reason' => $price->reason,
                        'is_active' => (bool) $price->is_active,
                    ]),
                ]),
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
