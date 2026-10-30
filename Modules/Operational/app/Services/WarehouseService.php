<?php

namespace Modules\Operational\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Operational\Models\Warehouse;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Operational\Models\Branch;
use Spatie\QueryBuilder\AllowedFilter;

class WarehouseService extends BaseService
{
    public function __construct(protected Warehouse $model) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $config = new DataTableConfig(
            allowedFields: [
                'id',
                'code',
                'name',
                'type',
                'phone',
                'address',
                'is_active',
                'created_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('is_active'),
                AllowedFilter::callback('branch_id', fn($query, $value) => $query->whereHas(
                    'branches',
                    fn($branches) => $branches->where('branches.id', $value)
                )),
            ],
            with: ['branches:id,name,code', 'regions:id,name,code,type'],
            allowedSorts: ['name', 'code', 'created_at'],
            searchableColumns: ['name', 'code', 'phone', 'address'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }


    public function getOptions(): \Illuminate\Support\Collection
    {
        return DB::table('warehouses as w')
            ->leftJoin('warehouse_regions as wr', function ($join) {
                $join->on('wr.warehouse_id', '=', 'w.id')
                    ->where('wr.merchant_id', '=', MerchantContext::id());
            })
            ->leftJoin('branch_warehouses as bw', function ($join) {
                $join->on('bw.warehouse_id', '=', 'w.id')
                    ->where('bw.merchant_id', '=', MerchantContext::id());
            })
            ->leftJoin('branches as b', function ($join) {
                $join->on('b.id', '=', 'bw.branch_id')
                    ->whereNull('b.deleted_at');
            })
            ->where('w.merchant_id', MerchantContext::id())
            ->whereNull('w.deleted_at')
            ->orderBy('w.name')
            ->get([
                'w.id', 'w.name', 'w.code', 'w.is_active', 'wr.region_id',
                'b.id as owner_branch_id', 'b.name as owner_branch_name', 'b.code as owner_branch_code',
            ])
            ->groupBy('id')
            ->map(fn($rows) => [
                'id' => (int) $rows->first()->id,
                'name' => $rows->first()->name,
                'code' => $rows->first()->code,
                'is_active' => (bool) $rows->first()->is_active,
                'region_ids' => $rows->pluck('region_id')->filter()->map(fn($id) => (int) $id)->values()->all(),
                'owner_branch_id' => $rows->first()->owner_branch_id ? (int) $rows->first()->owner_branch_id : null,
                'owner_branch_name' => $rows->first()->owner_branch_name,
                'owner_branch_code' => $rows->first()->owner_branch_code,
            ]);
    }

    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            $warehouse = Warehouse::query()->create([
                'merchant_id' => MerchantContext::id(),
                'name' => $data['name'],
                'slug' => uniqueSlug('warehouses', $data['name']),
                'code' => strtoupper($data['code']),
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncRegions($warehouse, $data['region_ids']);

            return $warehouse->load(['branches:id,name,code', 'regions:id,name,code,type']);
        });
    }

    public function createDefaultForBranch(Branch $branch): Warehouse
    {
        // Kepemilikan cabang disimpan pada pivot branch_warehouses.
        $existing = $branch->warehouses()
            ->wherePivot('is_primary', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $warehouse = Warehouse::create([
            'merchant_id' => $branch->merchant_id,   // sama dengan cabangnya, tidak boleh dari input luar
            'slug'        => uniqueSlug('warehouses', $branch->name),
            'code'        => $this->generateCode($branch),
            'name'        => 'Gudang ' . $branch->name,
            'is_active'   => true,
        ]);

        if ($branch->region_id) {
            $this->syncRegions($warehouse, [(int) $branch->region_id]);
        }

        return $warehouse;
    }

    public function update(
        Warehouse $warehouse,
        array $data
    ): Warehouse {
        return DB::transaction(function () use ($warehouse, $data) {
            $warehouse->update([
                'name'    => $data['name'],
                'slug' => Str::slug($data['name']),
                'code'    => strtoupper($data['code']),
                'address' => $data['address'] ?? null,
                'phone'   => $data['phone'] ?? null,
                'is_active'  => $data['is_active'],
            ]);

            $this->syncRegions($warehouse, $data['region_ids']);

            return $warehouse->refresh()->load(['branches:id,name,code', 'regions:id,name,code,type']);
        });
    }

    private function syncRegions(Warehouse $warehouse, array $regionIds): void
    {
        $merchantId = (int) MerchantContext::id();
        $regionIds = array_values(array_unique(array_map('intval', $regionIds)));
        $validCount = DB::table('regions')
            ->where('merchant_id', $merchantId)
            ->whereIn('id', $regionIds)
            ->where('is_active', true)
            ->count();

        if ($validCount !== count($regionIds)) {
            throw ValidationException::withMessages([
                'region_ids' => 'Satu atau lebih regional tidak aktif atau bukan milik merchant ini.',
            ]);
        }

        $branchesOutsideRegions = DB::table('branch_warehouses as bw')
            ->join('branches as b', 'b.id', '=', 'bw.branch_id')
            ->where('bw.merchant_id', $merchantId)
            ->where('bw.warehouse_id', $warehouse->id)
            ->where('b.merchant_id', $merchantId)
            ->whereNull('b.deleted_at')
            ->where(function ($query) use ($regionIds) {
                $query->WhereNotIn('b.region_id', $regionIds);
            })
            ->count();

        if ($branchesOutsideRegions > 0) {
            throw ValidationException::withMessages([
                'region_ids' => 'Regional gudang tidak dapat diubah karena masih digunakan cabang di luar regional yang dipilih.',
            ]);
        }

        $warehouse->regions()->sync(collect($regionIds)->mapWithKeys(
            fn(int $regionId) => [$regionId => ['merchant_id' => $merchantId]]
        )->all());
    }

    public function delete(Warehouse $warehouse): void
    {
        $this->ensureNotMainWarehouse($warehouse);
        $this->ensureNoInventory($warehouse);

        $warehouse->delete();
    }

    // -------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------

    private function ensureNotMainWarehouse(
        Warehouse $warehouse
    ): void {
        if (DB::table('branch_warehouses')
            ->where('merchant_id', MerchantContext::id())
            ->where('warehouse_id', $warehouse->id)
            ->where('is_primary', true)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'warehouse' => '👑 Mau lengser boleh, tapi mahkotanya dipindahin dulu yaa',
            ]);
        }
    }

    private function ensureNoInventory(
        Warehouse $warehouse
    ): void {
        // Nanti dicek saat module Inventory sudah ada
        // $hasInventory = $warehouse->inventories()->exists();
        // if ($hasInventory) { throw ... }
    }


    /**
     * Kode unik per merchant, contoh: WH-JKT01-001.
     * Dihitung per cabang supaya urutannya tidak melompat antar cabang.
     */
    private function generateCode(Branch $branch): string
    {
        $prefix = 'WH-' . $branch->code . '-';
        $sequence = Warehouse::withTrashed()
            ->where('merchant_id', $branch->merchant_id)
            ->where('code', 'like', $prefix . '%')
            ->count() + 1;

        return sprintf('WH-%s-%03d', $branch->code, $sequence);
    }
}
