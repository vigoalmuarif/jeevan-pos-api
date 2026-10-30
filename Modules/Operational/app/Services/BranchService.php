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
    public function __construct(
        protected Branch $model,
        protected WarehouseService $warehouse,
        protected BranchWarehouseService $branchWarehouses,
    ) {}

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
            allowedSorts: ['name', 'code', 'warehouses_count', 'created_at'],
            searchableColumns: ['name', 'code', 'email', 'phone', 'postal_code'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }

    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            // Cabang pertama otomatis main. Keunikan dijaga partial index, bukan hanya cek ini.
            $isFirst = ! Branch::where('merchant_id', MerchantContext::id())->exists();

            $slug = uniqueSlug('branches', $data['name']);

            $branch = Branch::create([
                'merchant_id'     => MerchantContext::id(),
                'name'            => $data['name'],
                'slug'            => $slug,
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

            // Gudang default = stok cabang itu sendiri (pemilik: cabang ini).
            $warehouse  = $this->warehouse->createDefaultForBranch($branch);
       

            // Jika set sebagai main, unset branch lain
            if ($isFirst || ! empty($data['is_main'])) {
                $this->setAsMain($branch);
            }

            $this->syncWarehouses(
                $branch,
                $warehouse ? [$warehouse->id] : [],
                $warehouse->id ?? null,
            );

            return $branch->load('warehouses');
        });
    }

    public function update(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data) {
            $branch->update([
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

            $this->syncWarehouses(
                $branch,
                $data['warehouse_ids'],
                primaryId: isset($data['primary_warehouse_id']) ? (int) $data['primary_warehouse_id'] : null,
            );

            return $branch->refresh()->load('warehouses');
        });
    }

    private function syncWarehouses(Branch $branch, array $ids, ?int $primaryId = null): void
    {
        $merchantId = (int) MerchantContext::id();
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            throw ValidationException::withMessages(['warehouse_ids' => 'Pilih minimal satu gudang.']);
        }

        // Primary harus eksplisit dan termasuk dalam daftar; fallback ke elemen pertama.
        $primaryId ??= $ids[0];
        if (! in_array($primaryId, $ids, true)) {
            throw ValidationException::withMessages(['primary_warehouse_id' => 'Gudang utama harus termasuk dalam daftar gudang.']);
        }

        $valid = DB::table('warehouses')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)          // gudang nonaktif tidak boleh dipilih
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->count();

        if ($valid !== count($ids)) {
            throw ValidationException::withMessages(['warehouse_ids' => 'Satu atau lebih gudang tidak tersedia.']);
        }

        // Satu gudang memiliki satu pemilik cabang agar stok tidak ambigu.
        $ownedByAnotherBranch = DB::table('branch_warehouses as bw')
            ->join('branches as b', 'b.id', '=', 'bw.branch_id')
            ->where('bw.merchant_id', $merchantId)
            ->whereIn('bw.warehouse_id', $ids)
            ->where('bw.branch_id', '!=', $branch->id)
            ->whereNull('b.deleted_at')
            ->pluck('bw.warehouse_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        if ($ownedByAnotherBranch->isNotEmpty()) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Satu atau lebih gudang sudah dimiliki cabang lain: ' . $ownedByAnotherBranch->implode(', ') . '.',
            ]);
        }

        // Gudang yang akan dilepas tidak boleh masih punya saldo / transfer terbuka.
        $this->assertRemovableWarehouses($branch, $ids);

        // Satu-satunya pintu mutasi relasi + primary (bukan sync() langsung).
        $this->branchWarehouses->replaceAll($branch, $ids, $primaryId);
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

    private function assertRemovableWarehouses(Branch $branch, array $selectedIds): void
    {
        $merchantId = (int) MerchantContext::id();
        $selectedIds = array_values(array_unique(array_map('intval', $selectedIds)));
        $removedIds = DB::table('branch_warehouses')
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branch->id)
            ->whereNotIn('warehouse_id', $selectedIds)
            ->pluck('warehouse_id')
            ->map(fn($id) => (int) $id)
            ->values();

        if ($removedIds->isEmpty()) {
            return;
        }

        $hasStock = DB::table('warehouse_stocks')
            ->whereIn('warehouse_id', $removedIds->all())
            ->where('quantity', '!=', 0)
            ->exists();

        if ($hasStock) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Gudang yang dilepas masih memiliki saldo stok. Pindahkan atau kosongkan stok terlebih dahulu.',
            ]);
        }

        $hasOpenTransfer = DB::table('stock_transfers')
            ->where('merchant_id', $merchantId)
            ->where(function ($query) use ($removedIds) {
                $query->whereIn('from_warehouse_id', $removedIds->all())
                    ->orWhereIn('to_warehouse_id', $removedIds->all());
            })
            ->whereIn('status', ['draft', 'shipped'])
            ->exists();

        if ($hasOpenTransfer) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Gudang yang dilepas masih terlibat transfer stok aktif. Selesaikan atau batalkan transfer terlebih dahulu.',
            ]);
        }

        $hasDraftAdjustment = DB::table('stock_adjustments')
            ->where('merchant_id', $merchantId)
            ->whereIn('warehouse_id', $removedIds->all())
            ->where('status', 'draft')
            ->exists();

        if ($hasDraftAdjustment) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Gudang yang dilepas masih memiliki stock adjustment draft. Selesaikan atau hapus dokumen tersebut terlebih dahulu.',
            ]);
        }
    }


}
