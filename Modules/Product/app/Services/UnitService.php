<?php

namespace Modules\Product\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Product\Models\Unit;
use Spatie\QueryBuilder\AllowedFilter;

class UnitService extends BaseService
{
    public function __construct(protected Unit $model) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $config = new DataTableConfig(
            allowedFilters: [
                AllowedFilter::exact('name'),
                AllowedFilter::exact('code'),
                AllowedFilter::exact('is_system'),
                AllowedFilter::exact('is_active'),
            ],
            allowedSorts: ['name', 'created_at'],
            searchableColumns: ['name', 'code'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }

    public function store(array $data): Unit
    {
        $is_merchant = auth()->guard('merchant')->check();

        $unit = $this->model::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'merchant_id' => $is_merchant  ? MerchantContext::id() : null,
            'is_system' => $is_merchant  ? false : true,
            'is_active' => true
        ]);

        return $unit;
    }

    public function update(array $data, Unit $unit): Unit
    {
        $is_merchant = auth()->guard('merchant')->check();

        $unit->name = $data['name'];
        $unit->code = $data['code'];
        $unit->merchant_id = $is_merchant ? MerchantContext::id() : null;
        $unit->is_system = $is_merchant ? false : true;
        $unit->is_active = isset($data['is_active']) ? $data['is_active'] : true;
        $unit->save();

        return $unit;
    }
}
