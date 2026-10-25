<?php

namespace Modules\Product\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Product\Models\Category;
use Spatie\QueryBuilder\AllowedFilter;

class CategoryService extends BaseService
{
     public function __construct(protected Category $model) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $config = new DataTableConfig(
            allowedFilters: [
                AllowedFilter::exact('name'),
                AllowedFilter::exact('code'),
                AllowedFilter::exact('is_active'),
            ],
            allowedSorts: ['name', 'code', 'created_at'],
            searchableColumns: ['name', 'code'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }

    public function store(array $data): Category
    {
        $is_merchant = auth()->guard('merchant')->check();

        $category = $this->model::create([
            'name' => $data['name'],
            'code' => Str::upper(trim($data['code'])),
            'slug' => Str::slug($data['name']),
            'merchant_id' => $is_merchant  ? MerchantContext::id() : null,
            'description' => $data['description'] ?? null,
            'is_active' => true
        ]);

        return $category;
    }

    public function update(array $data, Category $category): Category
    {
        $is_merchant = auth()->guard('merchant')->check();

        $category->name = $data['name'];
        $category->code = Str::upper(trim($data['code']));
        $category->slug = Str::slug($data['name']);
        $category->merchant_id = $is_merchant ? MerchantContext::id() : $category->merchant_id;
        $category->description = $data['description'] ?? null;
        $category->is_active = $data['is_active'] ?? $category->is_active;
        $category->save();

        return $category;
    }
}
