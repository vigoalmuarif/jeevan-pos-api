<?php

namespace Modules\Operational\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\Warehouse;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Scopes\MerchantScope;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
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
                'is_main',
                'is_active',
                'created_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('is_main'),
                AllowedFilter::exact('branch_id'),
                AllowedFilter::exact('region_id'),
            ],
            with: ['branches', 'regions:id,name,code,type'],
            allowedSorts: ['name', 'code' ,'created_at'],
            searchableColumns: ['name', 'code', 'phone', 'address'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }

    
    public function create(?Branch $branch, array $data): Warehouse
    {
        return DB::transaction(function () use ($branch, $data) {
            // jika bukan akses lewat post branches/{branches}/warehouses maka $branch null
            if(empty($branch?->id)){
                $branch = Branch::find($data['branch_id']);
            }
            
            // Jika ini warehouse pertama di branch, otomatis main
            $isFirst = Warehouse::where('branch_id', $branch->id)
                                ->count() === 0;

            $warehouse = Warehouse::create([
                'merchant_id' => MerchantContext::id(),
                'branch_id'   => $branch->id,
                'name'        => $data['name'],
                'slug'        => Str::slug($data['name']),
                'code'        => strtoupper($data['code']),
                'address'     => $data['address'] ?? null,
                'phone'       => $data['phone'] ?? null,
                'is_main'     => $isFirst ? true : ($data['is_main'] ?? false),
                'is_active'   => $data['is_active'],
            ]);

            if ($warehouse->is_main) {
                $this->setAsMain($warehouse, $branch);
            }

            return $warehouse;
        });
    }

    public function update(
        Warehouse $warehouse,
        array $data
    ): Warehouse {
        return DB::transaction(function () use ($warehouse, $data) {
            $warehouse->update([
                'branch_id' => $data['branch_id'],
                'name'    => $data['name'],
                'slug' => Str::slug($data['name']),
                'code'    => strtoupper($data['code']),
                'address' => $data['address'] ?? null,
                'phone'   => $data['phone'] ?? null,
                'is_active'  => $data['is_active'],
            ]);

            if (!empty($data['is_main']) && $data['is_main']) {
                $this->setAsMain(
                    $warehouse,
                    $warehouse->branch
                );
            }

            return $warehouse;
        });
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

    private function setAsMain(
        Warehouse $warehouse,
        Branch $branch
    ): void {
        Warehouse::withoutGlobalScope(MerchantScope::class)
                 ->where('branch_id', $branch->id)
                 ->where('id', '!=', $warehouse->id)
                 ->update(['is_main' => false]);

        $warehouse->update(['is_main' => true]);
    }

    private function ensureNotMainWarehouse(
        Warehouse $warehouse
    ): void {
        if ($warehouse->is_main) {
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
}