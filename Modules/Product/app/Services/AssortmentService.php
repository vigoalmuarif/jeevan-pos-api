<?php

namespace Modules\Product\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Product\Models\AssortmentGroup;

class AssortmentService
{
    public function index()
    {
        return AssortmentGroup::query()->where('merchant_id', $this->merchantId())
            ->withCount('variants')->orderBy('name')->get();
    }

    public function create(array $data): AssortmentGroup
    {
        return AssortmentGroup::query()->create([...$data, 'merchant_id' => $this->merchantId(), 'is_active' => $data['is_active'] ?? true]);
    }

    public function update(int $id, array $data): AssortmentGroup
    {
        $group = $this->group($id);
        $group->fill($data)->save();
        $this->rebuildGroupBranches($id);
        return $group->refresh();
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $merchantId = $this->merchantId();
            $group = $this->group($id);
            $branchIds = DB::table('branch_assortment_groups')->where('merchant_id', $merchantId)->where('assortment_group_id', $id)->pluck('branch_id');
            DB::table('branch_assortment_groups')->where('merchant_id', $merchantId)->where('assortment_group_id', $id)->delete();
            DB::table('assortment_group_products')->where('merchant_id', $merchantId)->where('assortment_group_id', $id)->delete();
            $group->delete();
            foreach ($branchIds as $branchId) $this->rebuildBranch((int) $branchId);
        });
    }

    public function syncVariants(int $groupId, array $variantIds): AssortmentGroup
    {
        $merchantId = $this->merchantId();
        return DB::transaction(function () use ($groupId, $variantIds, $merchantId) {
            $group = $this->group($groupId);
            $ids = collect($variantIds)->map(fn ($id) => (int) $id)->unique()->values();
            $count = DB::table('product_variants as pv')->join('products as p', 'p.id', '=', 'pv.product_id')
                ->where('pv.merchant_id', $merchantId)->where('p.merchant_id', $merchantId)
                ->whereNull('pv.deleted_at')->whereNull('p.deleted_at')->whereIn('pv.id', $ids)->count();
            if ($count !== $ids->count()) throw ValidationException::withMessages(['variant_ids' => 'Satu atau lebih varian tidak tersedia untuk merchant ini.']);

            DB::table('assortment_group_products')->where('merchant_id', $merchantId)->where('assortment_group_id', $group->id)->delete();
            $now = now();
            if ($ids->isNotEmpty()) {
                DB::table('assortment_group_products')->insert($ids->map(fn ($id) => [
                    'merchant_id' => $merchantId, 'assortment_group_id' => $group->id,
                    'product_variant_id' => $id, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            }
            $this->rebuildGroupBranches($group->id);
            return $group->loadCount('variants');
        });
    }

    public function syncBranchGroups(int $branchId, array $groupIds): array
    {
        $merchantId = $this->merchantId();
        return DB::transaction(function () use ($branchId, $groupIds, $merchantId) {
            $this->assertBranch($branchId, $merchantId);
            $ids = collect($groupIds)->map(fn ($id) => (int) $id)->unique()->values();
            $count = AssortmentGroup::query()->where('merchant_id', $merchantId)->where('is_active', true)->whereIn('id', $ids)->count();
            if ($count !== $ids->count()) throw ValidationException::withMessages(['assortment_group_ids' => 'Satu atau lebih grup tidak aktif atau bukan milik merchant ini.']);

            DB::table('branch_assortment_groups')->where('merchant_id', $merchantId)->where('branch_id', $branchId)->delete();
            $now = now();
            if ($ids->isNotEmpty()) DB::table('branch_assortment_groups')->insert($ids->map(fn ($id) => [
                'merchant_id' => $merchantId, 'branch_id' => $branchId, 'assortment_group_id' => $id,
                'effective_from' => null, 'effective_until' => null, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
            $this->rebuildBranch($branchId);
            return $this->activeProducts($branchId);
        });
    }

    public function setOverride(int $branchId, int $variantId, array $data): array
    {
        $merchantId = $this->merchantId();
        $this->assertBranch($branchId, $merchantId);
        $valid = DB::table('product_variants as pv')->join('products as p', 'p.id', '=', 'pv.product_id')
            ->where('pv.id', $variantId)->where('pv.merchant_id', $merchantId)->where('p.merchant_id', $merchantId)
            ->whereNull('pv.deleted_at')->whereNull('p.deleted_at')->exists();
        if (! $valid) throw ValidationException::withMessages(['variant' => 'Varian tidak tersedia untuk merchant ini.']);

        DB::transaction(function () use ($merchantId, $branchId, $variantId, $data) {
            DB::table('branch_product_overrides')->updateOrInsert(
                ['merchant_id' => $merchantId, 'branch_id' => $branchId, 'product_variant_id' => $variantId],
                ['override_type' => $data['override_type'], 'reason' => $data['reason'] ?? null, 'is_active' => $data['is_active'] ?? true, 'updated_at' => now(), 'created_at' => now()]
            );
            $this->rebuildBranch($branchId);
        });
        return $this->activeProducts($branchId);
    }

    public function removeOverride(int $branchId, int $variantId): array
    {
        $merchantId = $this->merchantId();
        $this->assertBranch($branchId, $merchantId);
        DB::transaction(function () use ($merchantId, $branchId, $variantId) {
            DB::table('branch_product_overrides')->where('merchant_id', $merchantId)->where('branch_id', $branchId)->where('product_variant_id', $variantId)->delete();
            $this->rebuildBranch($branchId);
        });
        return $this->activeProducts($branchId);
    }

    public function activeProducts(int $branchId): array
    {
        $merchantId = $this->merchantId();
        $this->assertBranch($branchId, $merchantId);
        return DB::table('branch_active_products as bap')
            ->join('product_variants as pv', 'pv.id', '=', 'bap.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->where('bap.merchant_id', $merchantId)->where('bap.branch_id', $branchId)
            ->orderBy('p.name')->orderBy('pv.sku')
            ->get(['p.id as product_id', 'p.name as product_name', 'pv.id as product_variant_id', 'pv.name as variant_name', 'pv.sku', 'bap.source', 'bap.resolved_at'])
            ->all();
    }

    public function availability(int $branchId): array
    {
        $merchantId = $this->merchantId();
        $this->assertBranch($branchId, $merchantId);

        return DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('branch_active_products as bap', function ($join) use ($branchId, $merchantId) {
                $join->on('bap.product_variant_id', '=', 'pv.id')
                    ->where('bap.branch_id', '=', $branchId)
                    ->where('bap.merchant_id', '=', $merchantId);
            })
            ->leftJoin('branch_product_overrides as bpo', function ($join) use ($branchId, $merchantId) {
                $join->on('bpo.product_variant_id', '=', 'pv.id')
                    ->where('bpo.branch_id', '=', $branchId)
                    ->where('bpo.merchant_id', '=', $merchantId)
                    ->where('bpo.is_active', '=', true);
            })
            ->where('pv.merchant_id', $merchantId)
            ->where('p.merchant_id', $merchantId)
            ->where('p.type', 'product')
            ->where('p.is_active', true)
            ->where('pv.is_active', true)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->orderBy('p.name')->orderBy('pv.sku')
            ->get([
                'p.id as product_id',
                'p.name as product_name',
                'pv.id as product_variant_id',
                'pv.name as variant_name',
                'pv.sku',
                'bap.source',
                'bpo.override_type',
                DB::raw('bap.product_variant_id IS NOT NULL as is_available'),
            ])
            ->map(fn ($row) => [
                ...((array) $row),
                'is_available' => (bool) $row->is_available,
            ])
            ->all();
    }

    public function refreshVariants(array $variantIds): void
    {
        $merchantId = $this->merchantId();
        if ($variantIds === []) return;
        $branchIds = DB::table('assortment_group_products as agp')
            ->join('branch_assortment_groups as bag', 'bag.assortment_group_id', '=', 'agp.assortment_group_id')
            ->where('agp.merchant_id', $merchantId)->where('bag.merchant_id', $merchantId)
            ->whereIn('agp.product_variant_id', $variantIds)->pluck('bag.branch_id')
            ->merge(DB::table('branch_product_overrides')->where('merchant_id', $merchantId)
                ->whereIn('product_variant_id', $variantIds)->pluck('branch_id'))
            ->unique();
        foreach ($branchIds as $branchId) $this->rebuildBranch((int) $branchId);
    }

    private function rebuildGroupBranches(int $groupId): void
    {
        $merchantId = $this->merchantId();
        $branchIds = DB::table('branch_assortment_groups')->where('merchant_id', $merchantId)->where('assortment_group_id', $groupId)->pluck('branch_id');
        foreach ($branchIds as $branchId) $this->rebuildBranch((int) $branchId);
    }

    private function rebuildBranch(int $branchId): void
    {
        $merchantId = $this->merchantId();
        $today = Carbon::today()->toDateString();
        $groupVariants = DB::table('branch_assortment_groups as bag')
            ->join('assortment_groups as ag', 'ag.id', '=', 'bag.assortment_group_id')
            ->join('assortment_group_products as agp', 'agp.assortment_group_id', '=', 'ag.id')
            ->join('product_variants as pv', 'pv.id', '=', 'agp.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->where('bag.merchant_id', $merchantId)->where('bag.branch_id', $branchId)
            ->where('ag.merchant_id', $merchantId)->where('ag.is_active', true)->whereNull('ag.deleted_at')
            ->where('agp.merchant_id', $merchantId)->where('pv.merchant_id', $merchantId)->where('p.merchant_id', $merchantId)
            ->where('pv.is_active', true)->where('p.is_active', true)->whereNull('pv.deleted_at')->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->whereNull('bag.effective_from')->orWhereDate('bag.effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('bag.effective_until')->orWhereDate('bag.effective_until', '>=', $today))
            ->distinct()->pluck('pv.id')->map(fn ($id) => (int) $id)->all();

        $active = array_fill_keys($groupVariants, 'assortment');
        $eligibleVariants = DB::table('product_variants as pv')->join('products as p', 'p.id', '=', 'pv.product_id')
            ->where('pv.merchant_id', $merchantId)->where('p.merchant_id', $merchantId)
            ->where('pv.is_active', true)->where('p.is_active', true)
            ->whereNull('pv.deleted_at')->whereNull('p.deleted_at')->pluck('pv.id')->map(fn ($id) => (int) $id)->all();
        $overrides = DB::table('branch_product_overrides')->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)->where('is_active', true)->get();
        foreach ($overrides as $override) {
            if (! in_array((int) $override->product_variant_id, $eligibleVariants, true)) continue;
            if ($override->override_type === 'include') $active[(int) $override->product_variant_id] = 'override_include';
            else unset($active[(int) $override->product_variant_id]);
        }

        DB::table('branch_active_products')->where('merchant_id', $merchantId)->where('branch_id', $branchId)->delete();
        if ($active) {
            $now = now();
            DB::table('branch_active_products')->insert(collect($active)->map(fn ($source, $variantId) => [
                'merchant_id' => $merchantId, 'branch_id' => $branchId, 'product_variant_id' => $variantId,
                'source' => $source, 'resolved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }
    }

    private function group(int $id): AssortmentGroup
    {
        return AssortmentGroup::query()->where('merchant_id', $this->merchantId())->findOrFail($id);
    }

    private function assertBranch(int $branchId, int $merchantId): void
    {
        $exists = DB::table('branches')->where('id', $branchId)->where('merchant_id', $merchantId)->where('is_active', true)->whereNull('deleted_at')->exists();
        if (! $exists) throw ValidationException::withMessages(['branch_id' => 'Cabang tidak aktif atau bukan milik merchant ini.']);
    }

    private function merchantId(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');
        return (int) MerchantContext::id();
    }
}
