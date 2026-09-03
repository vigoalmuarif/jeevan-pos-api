<?php

namespace Modules\Operational\Services;

use Illuminate\Database\Eloquent\Collection;
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
use Modules\Operational\Models\BranchWarehouse;
use Modules\Operational\Models\Warehouse;
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
                'regions_id',
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

            // Auto buat warehouse utama
            $warehouse = Warehouse::create([
                'merchant_id' => MerchantContext::id(),
                'name'        => $isFirst ? 'Main Warehouse' : $data['warehouse_name'],
                'code'        => $isFirst  ? 'WH-' . $branch->code : ($data['warehouse_code'] ?? 'WH-' . $branch->code),
                'slug'        => Str::slug($data['name']),
                'type'        => 'storage',
                'address'     => isset($data['is_same_address']) && $data['is_same_address'] == "true"
                                ? $data['address'] 
                                : (isset($data['warehouse_address']) ? $data['warehouse_address'] : null),
                'is_active'   => $data['is_active'],
            ]);

           BranchWarehouse::create([
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'merchant_id' => MerchantContext::id(),
                'is_primary' => true,
                'is_active' => true,
            ]);

            return $branch;
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

            return $branch;
        });
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
