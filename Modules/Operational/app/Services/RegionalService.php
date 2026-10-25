<?php

namespace Modules\Operational\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Operational\Models\Region;
use Spatie\QueryBuilder\AllowedFilter;

class RegionalService extends BaseService
{
    public function __construct(protected Region $model) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $config = new DataTableConfig(
            allowedFields: [
                'id',
                'parent_region_id',
                'code',
                'name',
                'type',
                'is_active'
            ],
            allowedFilters: [
                AllowedFilter::exact('is_active'),
            ],
            with: ['parent:id,name,code,type'],
            withCount: ['childs'],
            allowedSorts: ['name', 'code', 'type', 'created_at'],
            searchableColumns: ['name', 'code', 'type'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }

    public function create(array $data): Region
    {
        return DB::transaction(function () use ($data): Region {
            $region = new Region();
            $region->fill([
                'merchant_id' => MerchantContext::id(),
                'parent_region_id' => $data['parent_region_id'] ?? null,
                'name' => trim($data['name']),
                'code' => strtoupper(trim($data['code'])),
                'type' => $data['type'],
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->assertParentIsValid($region, $region->parent_region_id);
            $region->save();

            return $region->load('parent:id,name,code,type');
        });
    }

    public function update(Region $region, array $data): Region
    {
        return DB::transaction(function () use ($region, $data): Region {
            if (! ($data['is_active'] ?? $region->is_active) && $this->isAssigned($region)) {
                throw ValidationException::withMessages([
                    'is_active' => 'Regional yang masih digunakan cabang atau gudang tidak dapat dinonaktifkan.',
                ]);
            }

            $region->fill([
                'parent_region_id' => $data['parent_region_id'] ?? null,
                'name' => trim($data['name']),
                'code' => strtoupper(trim($data['code'])),
                'type' => $data['type'],
                'is_active' => $data['is_active'] ?? $region->is_active,
            ]);
            $this->assertParentIsValid($region, $region->parent_region_id);
            $region->save();

            return $region->refresh()->load('parent:id,name,code,type');
        });
    }

    public function delete(Region $region): void
    {
        DB::transaction(function () use ($region): void {
            if ($region->childs()->exists()) {
                throw ValidationException::withMessages([
                    'region' => 'Regional ini masih memiliki regional turunan.',
                ]);
            }

            if ($this->isAssigned($region)) {
                throw ValidationException::withMessages([
                    'region' => 'Regional masih digunakan cabang atau gudang.',
                ]);
            }

            $region->delete();
        });
    }

    public function getOptions()
    {
        return Region::query()
            ->orderBy('name')
            ->get(['id', 'parent_region_id', 'name', 'code', 'type', 'is_active']);
    }

    private function assertParentIsValid(Region $region, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $visited = [];
        $currentId = $parentId;
        while ($currentId !== null) {
            if ($currentId === (int) $region->id || isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_region_id' => 'Regional induk tidak boleh membuat siklus.',
                ]);
            }

            $visited[$currentId] = true;
            $currentId = Region::query()
                ->whereKey($currentId)
                ->value('parent_region_id');
            $currentId = $currentId === null ? null : (int) $currentId;
        }
    }

    private function isAssigned(Region $region): bool
    {
        return DB::table('branches')
            ->where('merchant_id', MerchantContext::id())
            ->where('region_id', $region->id)
            ->exists()
            || DB::table('warehouse_regions')
                ->where('merchant_id', MerchantContext::id())
                ->where('region_id', $region->id)
                ->exists();
    }
}
