<?php

namespace Modules\Product\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Product\Models\Brand;
use Spatie\QueryBuilder\AllowedFilter;

class BrandService extends BaseService
{
     public function __construct(protected Brand $model) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $config = new DataTableConfig(
            allowedFilters: [
                AllowedFilter::exact('name'),
                AllowedFilter::exact('is_active'),
            ],
            allowedSorts: ['name', 'created_at'],
            searchableColumns: ['name'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }

    public function store(array $data): Brand
    {
        $is_merchant = auth()->guard('merchant')->check();

        $brand = $this->model::create([
            'name' => $data['name'],
            'merchant_id' => $is_merchant  ? MerchantContext::id() : null,
            'is_active' => true
        ]);

        return $brand;
    }

    public function update(array $data, Brand $brand): Brand
    {
        $is_merchant = auth()->guard('merchant')->check();

        $brand->name = $data['name'];
        $brand->merchant_id = $is_merchant ? MerchantContext::id() : null;
        $brand->is_active = isset($data['is_active']) ? $data['is_active'] : true;
        $brand->save();

        return $brand;
    }
}
