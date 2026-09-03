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
                AllowedFilter::exact('is_active'),
            ],
            allowedSorts: ['name', 'created_at'],
            searchableColumns: ['name'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }

    public function store(array $data): Category
    {
        $is_merchant = auth()->guard('merchant')->check();

        $category = $this->model::create([
            'name' => $data['name'],
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
        $category->slug = Str::slug($data['name']);
        $category->merchant_id = $is_merchant ? MerchantContext::id() : null;
        $category->description = $data['description'];
        $category->is_active = isset($data['is_active']) ? $data['is_active'] : true;
        $category->save();

        return $category;
    }
}
