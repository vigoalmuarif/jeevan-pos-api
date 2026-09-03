<?php

namespace Modules\Operational\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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
            allowedSorts: ['name', 'code'],
            searchableColumns: ['name', 'code', 'type'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }

    public function create(array $data): Region
    {
        return DB::transaction(function () use ($data) {
           

            return $data;
        });
    }

    public function update(Region $region, array $data): Region
    {
        return DB::transaction(function () use ($region, $data) {
            $region->update([
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
        });

    }

    public function delete(Region $region): void
    {
    }
}
