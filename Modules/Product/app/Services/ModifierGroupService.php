<?php

declare(strict_types=1);

namespace Modules\Product\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Product\Models\ModifierGroup;
use Modules\Product\Models\ModifierOption;
use Modules\Product\Models\Product;

class ModifierGroupService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $query = ModifierGroup::query()
            ->where('merchant_id', $merchantId)
            ->with(['options'])
            ->withCount('products');

        if (! empty($filters['search'])) {
            $like = '%'.mb_strtolower(trim((string) $filters['search'])).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->orderBy('sort_order')->orderBy('name')->paginate($perPage);
    }

    public function show(int $id): ModifierGroup
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        return ModifierGroup::query()
            ->where('merchant_id', $merchantId)
            ->with(['options'])
            ->withCount('products')
            ->findOrFail($id);
    }

    public function create(array $data): ModifierGroup
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        return DB::transaction(function () use ($merchantId, $data) {
            $selectionType = (string) ($data['selection_type'] ?? 'single');
            $isRequired = (bool) ($data['is_required'] ?? false);
            $minSelect = $isRequired ? max(1, (int) ($data['min_select'] ?? 1)) : max(0, (int) ($data['min_select'] ?? 0));
            $maxSelect = $selectionType === 'single'
                ? 1
                : (isset($data['max_select']) && $data['max_select'] !== null ? max($minSelect, (int) $data['max_select']) : null);

            $group = ModifierGroup::query()->create([
                'merchant_id' => $merchantId,
                'name' => trim((string) $data['name']),
                'selection_type' => $selectionType,
                'is_required' => $isRequired,
                'min_select' => $minSelect,
                'max_select' => $maxSelect,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->syncOptions($merchantId, $group, $data['options'] ?? []);

            return $group->load(['options'])->loadCount('products');
        });
    }

    public function update(int $id, array $data): ModifierGroup
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        return DB::transaction(function () use ($merchantId, $id, $data) {
            $group = ModifierGroup::query()
                ->where('merchant_id', $merchantId)
                ->findOrFail($id);

            $selectionType = array_key_exists('selection_type', $data)
                ? (string) $data['selection_type']
                : $group->selection_type;
            $isRequired = array_key_exists('is_required', $data)
                ? (bool) $data['is_required']
                : (bool) $group->is_required;
            $minSelect = array_key_exists('min_select', $data)
                ? (int) $data['min_select']
                : (int) $group->min_select;
            if ($isRequired && $minSelect < 1) {
                $minSelect = 1;
            }
            $maxSelect = $selectionType === 'single'
                ? 1
                : (array_key_exists('max_select', $data)
                    ? ($data['max_select'] !== null ? max($minSelect, (int) $data['max_select']) : null)
                    : $group->max_select);

            $group->update([
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $group->name,
                'selection_type' => $selectionType,
                'is_required' => $isRequired,
                'min_select' => $minSelect,
                'max_select' => $maxSelect,
                'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $group->sort_order,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $group->is_active,
            ]);

            if (array_key_exists('options', $data)) {
                $this->syncOptions($merchantId, $group, $data['options'] ?? []);
            }

            return $group->load(['options'])->loadCount('products');
        });
    }

    public function delete(int $id): void
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        DB::transaction(function () use ($merchantId, $id) {
            $group = ModifierGroup::query()
                ->where('merchant_id', $merchantId)
                ->findOrFail($id);

            DB::table('product_modifier_groups')
                ->where('merchant_id', $merchantId)
                ->where('modifier_group_id', $group->id)
                ->delete();

            $group->delete();
        });
    }

    /**
     * Sync modifier groups attached to a product (ordered).
     *
     * @param  array<int, int>  $modifierGroupIds
     * @return Collection<int, ModifierGroup>
     */
    public function syncProductGroups(int $productId, array $modifierGroupIds): Collection
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $product = Product::query()
            ->where('merchant_id', $merchantId)
            ->findOrFail($productId);

        $uniqueIds = array_values(array_unique(array_filter(array_map('intval', $modifierGroupIds), fn (int $id) => $id > 0)));

        if (! empty($uniqueIds)) {
            $validCount = ModifierGroup::query()
                ->where('merchant_id', $merchantId)
                ->whereIn('id', $uniqueIds)
                ->count();

            if ($validCount !== count($uniqueIds)) {
                throw ValidationException::withMessages([
                    'modifier_group_ids' => 'Satu atau lebih grup modifier tidak ditemukan untuk merchant ini.',
                ]);
            }
        }

        DB::transaction(function () use ($merchantId, $product, $uniqueIds) {
            $syncData = [];
            foreach ($uniqueIds as $index => $groupId) {
                $syncData[$groupId] = [
                    'merchant_id' => $merchantId,
                    'sort_order' => $index,
                ];
            }
            $product->modifierGroups()->sync($syncData);
        });

        return $product->modifierGroups()->with(['options'])->get();
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    private function syncOptions(int $merchantId, ModifierGroup $group, array $options): void
    {
        $existingById = ModifierOption::query()
            ->where('merchant_id', $merchantId)
            ->where('modifier_group_id', $group->id)
            ->get()
            ->keyBy('id');

        $retainedIds = [];
        $now = now();
        $insertRows = [];

        foreach ($options as $index => $opt) {
            $optId = isset($opt['id']) ? (int) $opt['id'] : null;
            $payload = [
                'name' => trim((string) $opt['name']),
                'price_adjustment' => round((float) ($opt['price_adjustment'] ?? 0), 2),
                'is_default' => (bool) ($opt['is_default'] ?? false),
                'sort_order' => isset($opt['sort_order']) ? (int) $opt['sort_order'] : $index,
                'is_active' => array_key_exists('is_active', $opt) ? (bool) $opt['is_active'] : true,
            ];

            if ($optId !== null && $existingById->has($optId)) {
                $existingById->get($optId)->update($payload);
                $retainedIds[] = $optId;
            } else {
                $insertRows[] = [
                    'merchant_id' => $merchantId,
                    'modifier_group_id' => $group->id,
                    ...$payload,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $toDeleteIds = $existingById->keys()
            ->reject(fn ($id) => in_array((int) $id, $retainedIds, true))
            ->values()
            ->all();

        if (! empty($toDeleteIds)) {
            ModifierOption::query()
                ->where('merchant_id', $merchantId)
                ->where('modifier_group_id', $group->id)
                ->whereIn('id', $toDeleteIds)
                ->delete();
        }

        if (! empty($insertRows)) {
            ModifierOption::query()->insert($insertRows);
        }
    }
}
