<?php

namespace Modules\Product\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductImage;
use Modules\Product\Models\ProductVariant;
use Spatie\QueryBuilder\AllowedFilter;

class ProductService
{
    public function __construct(protected AssortmentService $assortmentService) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $merchantId = $this->requireMerchant();
        $query = $this->baseQuery($merchantId);
        $config = new DataTableConfig(
            allowedFilters: [
                AllowedFilter::exact('type'),
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('brand_id'),
                AllowedFilter::exact('track_stock'),
            ],
            allowedSorts: ['name', 'created_at', 'type'],
            searchableColumns: ['name', 'slug', 'description'],
            defaultSort: 'name',
        );

        return ServerSideTable::paginate($query, $config, $request);
    }

    public function find(int $id): Product
    {
        return $this->baseQuery($this->requireMerchant())->findOrFail($id);
    }

    public function create(array $data): Product
    {
        $merchantId = $this->requireMerchant();
        $storedImagePaths = [];

        try {
            return DB::transaction(function () use ($data, $merchantId, &$storedImagePaths) {
            $product = new Product();
            $product->fill($this->productAttributes($data));
            $product->merchant_id = $merchantId;
            $this->assertMasterReferences($data, $merchantId);
            $product->slug = $this->uniqueSlug($data['name'], $merchantId);
            $product->created_by = auth()->guard('merchant')->id();
            $product->updated_by = auth()->guard('merchant')->id();
            $product->save();

            $this->syncCategories($product, $data['category_ids'] ?? []);
            $this->syncSuppliers($product, $data['supplier_ids'] ?? [], $merchantId);
            $variantRows = $data['variants'] ?? [[
                'sku' => $data['sku'],
                'base_unit_id' => $data['base_unit_id'],
                'barcode' => $data['barcode'] ?? null,
                'cost' => $data['cost'] ?? 0,
                'default_price' => $data['default_price'] ?? 0,
                'additional_units' => [],
            ]];

            $defaultIndex = collect($variantRows)->search(fn (array $variant) => (bool) ($variant['is_default'] ?? false));
            if ($defaultIndex === false) {
                $defaultIndex = 0;
            }
            $sharedUnits = $this->sharedUnitsFromVariant($variantRows[$defaultIndex]);
            $this->assertSharedUnitsMatch($variantRows, $sharedUnits);
            $this->storeProductUnitConversions($product, $sharedUnits, $merchantId);

            foreach ($variantRows as $index => $variantData) {
                $variant = ProductVariant::query()->create([
                    'merchant_id' => $merchantId,
                    'product_id' => $product->id,
                    'sku' => $variantData['sku'],
                    'name' => $variantData['name'] ?? null,
                    'is_default' => $index === $defaultIndex,
                    'is_active' => true,
                    'created_by' => auth()->guard('merchant')->id(),
                    'updated_by' => auth()->guard('merchant')->id(),
                ]);

                $units = $this->variantUnitsWithSharedConversions($variantData, $sharedUnits);
                $this->createVariantUnits($variant, $units, $merchantId);
                $this->storeVariantImages($variant, $variantData['images'] ?? [], $merchantId, $storedImagePaths);
            }

                $this->storeImages($product, $data['images'] ?? [], $merchantId, $storedImagePaths);

                return $this->find($product->id);
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($storedImagePaths);
            throw $exception;
        }
    }

    public function update(int $id, array $data): Product
    {
        $merchantId = $this->requireMerchant();
        $storedImagePaths = [];

        try {
            return DB::transaction(function () use ($id, $data, $merchantId, &$storedImagePaths) {
            $product = Product::query()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($id);
            $oldName = $product->name;
            $this->assertMasterReferences($data, $merchantId);
            $product->fill($this->productAttributes($data));
            if (isset($data['name']) && $data['name'] !== $oldName) {
                $product->slug = $this->uniqueSlug($data['name'], $merchantId, $product->id);
            }
            $product->updated_by = auth()->guard('merchant')->id();
            $product->save();

            if (array_key_exists('category_ids', $data)) {
                $this->syncCategories($product, $data['category_ids']);
            }
            if (array_key_exists('supplier_ids', $data)) {
                $this->syncSuppliers($product, $data['supplier_ids'], $merchantId);
            }
            if (array_key_exists('is_active', $data)) {
                $this->assortmentService->refreshVariants($product->variants()->pluck('id')->all());
            }

                $this->syncImages($product, $data, $merchantId, $storedImagePaths);

                return $this->find($product->id);
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($storedImagePaths);
            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $merchantId = $this->requireMerchant();
        DB::transaction(function () use ($id, $merchantId) {
            $product = Product::query()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($id);
            $product->is_active = false;
            $product->updated_by = auth()->guard('merchant')->id();
            $variantIds = DB::table('product_variants')
                ->where('merchant_id', $merchantId)
                ->where('product_id', $product->id)
                ->pluck('id')
                ->all();
            $product->save();
            $deletedAt = now();
            if ($variantIds !== []) {
                DB::table('product_variant_units')
                    ->where('merchant_id', $merchantId)
                    ->whereIn('product_variant_id', $variantIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt, 'updated_at' => $deletedAt]);
                DB::table('product_variants')
                    ->where('merchant_id', $merchantId)
                    ->whereIn('id', $variantIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $deletedAt, 'updated_at' => $deletedAt]);
            }
            $product->delete();
            $this->assortmentService->refreshVariants($variantIds);
        });
    }

    public function addVariant(int $productId, array $data): Product
    {
        $merchantId = $this->requireMerchant();
        $storedImagePaths = [];

        try {
        DB::transaction(function () use ($productId, $data, $merchantId, &$storedImagePaths) {
            $product = Product::query()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($productId);
            $this->assertUnit($data['base_unit_id'], $merchantId);
            $sharedUnits = $product->unitConversions()->get()->keyBy('unit_id');
            $this->assertVariantMatchesSharedUnits($data, $sharedUnits);

            $variant = ProductVariant::query()->create([
                'merchant_id' => $merchantId,
                'product_id' => $product->id,
                'sku' => $data['sku'],
                'name' => $data['name'] ?? null,
                'is_default' => false,
                'is_active' => true,
                'created_by' => auth()->guard('merchant')->id(),
                'updated_by' => auth()->guard('merchant')->id(),
            ]);
            $units = $this->variantUnitsWithSharedConversions($data, $sharedUnits->values()->all());
            $this->createVariantUnits($variant, $units, $merchantId);
            $this->storeVariantImages($variant, $data['images'] ?? [], $merchantId, $storedImagePaths);
        });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($storedImagePaths);
            throw $exception;
        }

        return $this->find($productId);
    }

    public function setVariantActive(int $productId, int $variantId, bool $active): Product
    {
        $merchantId = $this->requireMerchant();
        $product = Product::query()->where('merchant_id', $merchantId)->findOrFail($productId);
        $variant = ProductVariant::query()->where('merchant_id', $merchantId)
            ->where('product_id', $product->id)->findOrFail($variantId);
        DB::transaction(function () use ($variant, $product, $active, $merchantId) {
            $replacement = null;
            if (! $active && $variant->is_default) {
                $replacement = ProductVariant::query()->where('merchant_id', $merchantId)->where('product_id', $product->id)
                    ->where('id', '<>', $variant->id)->where('is_active', true)->orderBy('id')->first();
                if (! $replacement) throw ValidationException::withMessages(['variant' => 'Varian default terakhir tidak dapat dinonaktifkan.']);
                $variant->is_default = false;
            }
            $variant->is_active = $active;
            $variant->updated_by = auth()->guard('merchant')->id();
            $variant->save();
            if ($replacement) {
                $replacement->is_default = true;
                $replacement->save();
            }
            $this->assortmentService->refreshVariants([$variant->id]);
        });

        return $this->find($productId);
    }

    public function updateVariant(int $productId, int $variantId, array $data): Product
    {
        $merchantId = $this->requireMerchant();
        $product = Product::query()->where('merchant_id', $merchantId)->findOrFail($productId);
        $variant = ProductVariant::query()->where('merchant_id', $merchantId)->where('product_id', $product->id)->findOrFail($variantId);
        $active = $data['is_active'] ?? $variant->is_active;
        $storedImagePaths = [];
        try {
        DB::transaction(function () use ($variant, $product, $merchantId, $active, $data, &$storedImagePaths) {
            $replacement = null;
            if (! $active && $variant->is_default) {
                $replacement = ProductVariant::query()->where('merchant_id', $merchantId)->where('product_id', $product->id)
                    ->where('id', '<>', $variant->id)->where('is_active', true)->orderBy('id')->first();
                if (! $replacement) throw ValidationException::withMessages(['is_active' => 'Varian default terakhir tidak dapat dinonaktifkan.']);
                $variant->is_default = false;
            }
            $variant->fill(array_intersect_key($data, array_flip(['sku', 'name', 'is_active'])))->save();
            $this->syncVariantImages($variant, $data, $merchantId, $storedImagePaths);
            if ($replacement) {
                $replacement->is_default = true;
                $replacement->save();
            }
            $this->assortmentService->refreshVariants([$variant->id]);
        });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($storedImagePaths);
            throw $exception;
        }
        return $this->find($productId);
    }

    private function baseQuery(int $merchantId): Builder
    {
        return Product::query()->where('merchant_id', $merchantId)
            ->with([
                'brand:id,name',
                'categories:id,name',
                'suppliers:id,name',
                'images',
                'unitConversions.unit:id,name,code',
                'variants' => fn ($query) => $query->orderByDesc('is_default')->orderBy('id'),
                'variants.images',
                'variants.units.unit:id,name,code',
                'variants.units.prices' => fn ($query) => $query->where('is_active', true)->orderBy('min_qty'),
            ]);
    }

    private function productAttributes(array $data): array
    {
        $keys = ['type', 'name', 'description', 'brand_id', 'track_stock', 'is_taxable', 'tax_rate', 'min_stock', 'weight', 'is_active'];
        $attributes = array_intersect_key($data, array_flip($keys));
        if (($data['type'] ?? null) === 'service' && ! array_key_exists('track_stock', $data)) {
            $attributes['track_stock'] = false;
        }

        return $attributes;
    }

    private function syncImages(Product $product, array $data, int $merchantId, array &$storedPaths): void
    {
        $files = $data['images'] ?? [];
        $removeIds = collect($data['remove_image_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $existing = ProductImage::query()->where('merchant_id', $merchantId)->where('product_id', $product->id);
        $existingCount = (clone $existing)->count();

        if ($removeIds->isNotEmpty()) {
            $removable = (clone $existing)->whereIn('id', $removeIds)->get();
            if ($removable->count() !== $removeIds->count()) {
                throw ValidationException::withMessages(['remove_image_ids' => 'Satu atau lebih gambar tidak tersedia untuk produk ini.']);
            }
        } else {
            $removable = collect();
        }

        if ($existingCount - $removable->count() + count($files) > 5) {
            throw ValidationException::withMessages(['images' => 'Satu produk maksimal memiliki lima gambar. Hapus gambar lama sebelum menambah yang baru.']);
        }

        if ($removable->isNotEmpty()) {
            $pathsToDelete = $removable->pluck('path')->all();
            ProductImage::query()->where('merchant_id', $merchantId)->where('product_id', $product->id)
                ->whereIn('id', $removeIds)->delete();
            DB::afterCommit(fn () => Storage::disk('public')->delete($pathsToDelete));
        }

        $this->storeImages($product, $files, $merchantId, $storedPaths, $existingCount - $removable->count());
    }

    private function storeImages(Product $product, array $files, int $merchantId, array &$storedPaths, int $sortOrderOffset = 0): void
    {
        foreach (array_values($files) as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->storePublicly("merchants/{$merchantId}/products/{$product->id}", 'public');
            if (! $path) {
                throw new \RuntimeException('Gambar produk gagal disimpan.');
            }
            $storedPaths[] = $path;

            $product->images()->create([
                'merchant_id' => $merchantId,
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
                'sort_order' => $sortOrderOffset + $index,
            ]);
        }
    }

    private function syncVariantImages(ProductVariant $variant, array $data, int $merchantId, array &$storedPaths): void
    {
        $files = $data['images'] ?? [];
        $removeIds = collect($data['remove_image_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $query = $variant->images()->where('merchant_id', $merchantId);
        $existingCount = (clone $query)->count();
        $removable = $removeIds->isEmpty() ? collect() : (clone $query)->whereIn('id', $removeIds)->get();
        if ($removable->count() !== $removeIds->count()) {
            throw ValidationException::withMessages(['remove_image_ids' => 'Gambar tidak tersedia untuk varian ini.']);
        }
        if ($existingCount - $removable->count() + count($files) > 5) {
            throw ValidationException::withMessages(['images' => 'Satu varian maksimal memiliki lima gambar.']);
        }
        if ($removable->isNotEmpty()) {
            $paths = $removable->pluck('path')->all();
            $variant->images()->where('merchant_id', $merchantId)->whereIn('id', $removeIds)->delete();
            DB::afterCommit(fn () => Storage::disk('public')->delete($paths));
        }
        $this->storeVariantImages($variant, $files, $merchantId, $storedPaths, $existingCount - $removable->count());
    }

    private function storeVariantImages(ProductVariant $variant, array $files, int $merchantId, array &$storedPaths, int $sortOrderOffset = 0): void
    {
        foreach (array_values($files) as $index => $file) {
            if (! $file instanceof UploadedFile) continue;
            $path = $file->storePublicly("merchants/{$merchantId}/products/{$variant->product_id}/variants/{$variant->id}", 'public');
            if (! $path) throw new \RuntimeException('Gambar varian gagal disimpan.');
            $storedPaths[] = $path;
            $variant->images()->create([
                'merchant_id' => $merchantId,
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
                'sort_order' => $sortOrderOffset + $index,
            ]);
        }
    }

    private function syncCategories(Product $product, array $categoryIds): void
    {
        $pivot = [];
        foreach (array_values(array_unique($categoryIds)) as $index => $id) {
            $pivot[$id] = ['is_primary' => $index === 0];
        }
        $product->categories()->sync($pivot);
    }

    private function syncSuppliers(Product $product, array $supplierIds, int $merchantId): void
    {
        $pivot = [];
        foreach (array_unique(array_map('intval', $supplierIds)) as $supplierId) {
            $pivot[$supplierId] = ['merchant_id' => $merchantId];
        }
        $product->suppliers()->sync($pivot);
    }

    private function assertMasterReferences(array $data, int $merchantId): void
    {
        if (! empty($data['brand_id'])) {
            $brandExists = DB::table('brands')->where('id', $data['brand_id'])->where('is_active', true)->whereNull('deleted_at')
                ->where(fn ($query) => $query->whereNull('merchant_id')->orWhere('merchant_id', $merchantId))->exists();
            if (! $brandExists) throw ValidationException::withMessages(['brand_id' => 'Merek tidak aktif atau bukan milik merchant ini.']);
        }
        foreach ($data['category_ids'] ?? [] as $categoryId) {
            $categoryExists = DB::table('categories')->where('id', $categoryId)->where('is_active', true)->whereNull('deleted_at')
                ->where(fn ($query) => $query->whereNull('merchant_id')->orWhere('merchant_id', $merchantId))->exists();
            if (! $categoryExists) throw ValidationException::withMessages(['category_ids' => 'Kategori tidak aktif atau bukan milik merchant ini.']);
        }
    }

    private function createVariantUnits(ProductVariant $variant, array $units, int $merchantId): void
    {
        foreach ($units as $unitData) {
            $this->assertUnit($unitData['unit_id'], $merchantId);
            $variant->units()->create([
                'merchant_id' => $merchantId,
                'unit_id' => $unitData['unit_id'],
                'conversion_value' => $unitData['conversion_value'] ?? 1,
                'is_base' => (bool) $unitData['is_base'],
                'barcode' => $unitData['barcode'] ?? null,
                'cost' => $unitData['cost'] ?? 0,
                'default_price' => $unitData['default_price'] ?? 0,
                'is_active' => true,
            ]);
        }
    }

    private function sharedUnitsFromVariant(array $variant): array
    {
        return [
            [
                'unit_id' => (int) $variant['base_unit_id'],
                'conversion_value' => 1,
                'is_base' => true,
            ],
            ...array_map(
                static fn (array $unit): array => [
                    'unit_id' => (int) $unit['unit_id'],
                    'conversion_value' => $unit['conversion_value'],
                    'is_base' => false,
                ],
                $variant['additional_units'] ?? [],
            ),
        ];
    }

    private function assertSharedUnitsMatch(array $variants, array $sharedUnits): void
    {
        $sharedIds = collect($sharedUnits)->pluck('unit_id')->sort()->values();
        foreach ($variants as $index => $variant) {
            $variantIds = collect($this->sharedUnitsFromVariant($variant))->pluck('unit_id')->sort()->values();
            if ($variantIds->all() !== $sharedIds->all()) {
                throw ValidationException::withMessages([
                    "variants.{$index}.base_unit_id" => 'Semua varian harus menggunakan satuan yang sama. Atur satuan konversi pada varian default produk.',
                ]);
            }
        }
    }

    private function assertVariantMatchesSharedUnits(array $variant, $sharedUnits): void
    {
        $submittedIds = collect($this->sharedUnitsFromVariant($variant))->pluck('unit_id')->sort()->values();
        $sharedIds = $sharedUnits->keys()->map(fn ($id) => (int) $id)->sort()->values();
        if ($submittedIds->all() !== $sharedIds->all()) {
            throw ValidationException::withMessages([
                'additional_units' => 'Varian baru harus menggunakan satuan konversi yang sudah ditetapkan pada produk.',
            ]);
        }
    }

    private function storeProductUnitConversions(Product $product, array $sharedUnits, int $merchantId): void
    {
        foreach ($sharedUnits as $unit) {
            $this->assertUnit($unit['unit_id'], $merchantId);
            $product->unitConversions()->create([
                'merchant_id' => $merchantId,
                ...$unit,
            ]);
        }
    }

    private function variantUnitsWithSharedConversions(array $variant, array $sharedUnits): array
    {
        $variantUnits = collect([
            [
                'unit_id' => (int) $variant['base_unit_id'],
                'barcode' => $variant['barcode'] ?? null,
                'cost' => $variant['cost'] ?? 0,
                'default_price' => $variant['default_price'] ?? 0,
            ],
            ...array_map(
                static fn (array $unit): array => $unit,
                $variant['additional_units'] ?? [],
            ),
        ])->keyBy('unit_id');

        return array_map(static function (array $sharedUnit) use ($variantUnits): array {
            $metadata = $variantUnits->get($sharedUnit['unit_id'], []);

            return [
                ...$sharedUnit,
                'barcode' => $metadata['barcode'] ?? null,
                'cost' => $metadata['cost'] ?? 0,
                'default_price' => $metadata['default_price'] ?? 0,
            ];
        }, $sharedUnits);
    }

    private function assertUnit(int $unitId, int $merchantId): void
    {
        $valid = DB::table('units')->where('id', $unitId)->where('is_active', true)->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('merchant_id')->orWhere('merchant_id', $merchantId))
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['base_unit_id' => 'Satuan tidak aktif atau bukan milik merchant ini.']);
        }
    }

    private function uniqueSlug(string $name, int $merchantId, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name) ?: 'product';
        $candidate = $slug;
        $suffix = 2;
        while (Product::withoutGlobalScopes()->where('merchant_id', $merchantId)->where('slug', $candidate)
            ->when($ignoreId, fn ($query) => $query->where('id', '<>', $ignoreId))->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function requireMerchant(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        return (int) MerchantContext::id();
    }
}
