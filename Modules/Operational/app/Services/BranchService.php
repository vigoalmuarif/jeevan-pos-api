<?php

namespace Modules\Operational\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Operational\Models\Branch;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Spatie\QueryBuilder\AllowedFilter;

class BranchService extends BaseService
{
    public function __construct(protected Branch $model) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $config = new DataTableConfig(
            allowedFields: [
                'id',
                'merchant_id',
                'primary_warehouse_id',
                'region_id',
                'code',
                'name',
                'email',
                'phone',
                'postal_code',
                'warehouses_count',
                'is_main',
                'is_active',
                'created_at',
                'updated_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('is_active'),
            ],
            with: ['region:id,name,code,type', 'mainWarehouse:id,name,code'],
            withCount: ['warehouses'],
            allowedSorts: ['name', 'code', 'warehouses_count' ,'created_at'],
            searchableColumns: ['name', 'code', 'email', 'phone', 'postal_code'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }

    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            // Jika ini branch pertama, otomatis jadi main
            $isFirst = Branch::count() === 0;

            $branch = Branch::create([
                'merchant_id'     => MerchantContext::id(),
                'name'            => $data['name'],
                'slug'            => Str::slug($data['name']),
                'code'            => strtoupper($data['code']),
                'email'           => $data['email'] ?? null,
                'phone'           => $data['phone'] ?? null,
                'region_id'       => $data['region_id'],
                'address'         => $data['address'] ?? null,
                'village_code'    => $data['village_code'] ?? null,
                'district_code'   => $data['district_code'] ?? null,
                'regency_code'    => $data['regency_code'] ?? null,
                'province_code'   => $data['province_code'] ?? null,
                'postal_code'     => $data['postal_code'] ?? null,
                'receipt_header'  => $data['receipt_header'] ?? null,
                'receipt_footer'  => $data['receipt_footer'] ?? null,
                'is_active'       => $data['is_active'],
            ]);

            // Jika set sebagai main, unset branch lain
            if ($isFirst ? true : ($data['is_main'] ?? false)) {
                $this->setAsMain($branch);
            }

            $this->syncWarehouses($branch, $data['warehouse_ids']);

            return $branch->load('warehouses');
        });
    }

    public function update(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data) {
            $branch->update([
                'merchant_id'     => MerchantContext::id(),
                'name'            => $data['name'],
                'code'            => strtoupper($data['code']),
                'email'           => $data['email'] ?? null,
                'phone'           => $data['phone'] ?? null,
                'region_id'       => $data['region_id'],
                'address'         => $data['address'] ?? null,
                'village_code'    => $data['village_code'] ?? null,
                'district_code'   => $data['district_code'] ?? null,
                'regency_code'    => $data['regency_code'] ?? null,
                'province_code'   => $data['province_code'] ?? null,
                'postal_code'     => $data['postal_code'] ?? null,
                'receipt_header'  => $data['receipt_header'] ?? null,
                'receipt_footer'  => $data['receipt_footer'] ?? null,
                'is_active'       => $data['is_active'],
            ]);

            // Update is_main jika diminta
            if (!empty($data['is_main']) && $data['is_main']) {
                $this->setAsMain($branch);
            }

            $this->syncWarehouses($branch, $data['warehouse_ids']);

            return $branch->refresh()->load('warehouses');
        });
    }

    private function syncWarehouses(Branch $branch, array $warehouseIds): void
    {
        $merchantId = (int) MerchantContext::id();
        $warehouseIds = array_values(array_unique(array_map('intval', $warehouseIds)));
        $validCount = DB::table('warehouses')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->whereIn('id', $warehouseIds)
            ->count();

        if ($validCount !== count($warehouseIds)) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Satu atau lebih gudang tidak tersedia untuk merchant ini.',
            ]);
        }

        $regionalWarehouseCount = DB::table('warehouse_regions')
            ->where('merchant_id', $merchantId)
            ->where('region_id', $branch->region_id)
            ->whereIn('warehouse_id', $warehouseIds)
            ->count();
        if ($regionalWarehouseCount !== count($warehouseIds)) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Gudang tidak berada dalam satu regional dengan cabang.',
            ]);
        }

        DB::table('branch_warehouses')
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branch->id)
            ->update(['is_primary' => false]);

        $branch->warehouses()->sync(collect($warehouseIds)->mapWithKeys(
            fn (int $warehouseId, int $index) => [$warehouseId => [
                'merchant_id' => $merchantId,
                'is_primary' => $index === 0,
                'is_active' => true,
            ]]
        )->all());

        $branch->forceFill(['primary_warehouse_id' => $warehouseIds[0] ?? null])->save();
    }

    public function delete(Branch $branch): void
    {
        $this->ensureNotMainBranch($branch);
        $this->ensureNoActiveUsers($branch);

        $branch->delete();
    }

    // -------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------

    private function setAsMain(Branch $branch): void
    {
        // Unset semua branch main milik merchant
        Branch::withoutGlobalScope(
            \Modules\Core\Scopes\MerchantScope::class
        )
            ->where('merchant_id', MerchantContext::id())
            ->where('id', '!=', $branch->id)
            ->update(['is_main' => false]);

        $branch->update(['is_main' => true]);
    }

    private function ensureNotMainBranch(Branch $branch): void
    {
        if ($branch->is_main) {
            throw ValidationException::withMessages([
                'branch' => 'Main branch belum bisa pamit. Tunjuk branch lain jadi main dulu, baru lanjut',
            ]);
        }
    }

    private function ensureNoActiveUsers(Branch $branch): void
    {
        $userCount = $branch->users()->count();

        if ($userCount > 0) {
            throw ValidationException::withMessages([
                'branch' => "Jangan kudeta dulu! Pilih cabang lain jadi main branch, baru yang ini bisa dihapus.",
            ]);
        }
    }
}
