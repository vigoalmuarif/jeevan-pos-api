<?php

namespace Modules\Inventory\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Inventory\Models\WarehouseStock;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\Warehouse;
use Spatie\QueryBuilder\AllowedFilter;

class WarehouseStockService
{
    public function __construct(protected WarehouseStock $model) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        $config = new DataTableConfig(
            allowedFields: [
                'id', 'product_id', 'product_name', 'product_variant_id', 'variant_name', 'sku',
                'branch_id', 'branch_name', 'warehouse_id', 'warehouse_name',
                'quantity', 'avg_cost', 'last_mutated_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('branch_id', 'branches.id'),
                AllowedFilter::exact('warehouse_id', 'warehouses.id'),
            ],
            allowedSorts: ['products.name', 'product_variants.sku', 'warehouse_stocks.quantity', 'warehouse_stocks.last_mutated_at'],
            searchableColumns: ['products.name', 'product_variants.name', 'product_variants.sku'],
            defaultSort: 'products.name',
        );

        $query = $this->model->newQuery()
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_stocks.warehouse_id')
            ->join('branch_warehouses', 'branch_warehouses.warehouse_id', '=', 'warehouses.id')
            ->join('branches', 'branches.id', '=', 'branch_warehouses.branch_id')
            ->join('product_variants', 'product_variants.id', '=', 'warehouse_stocks.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('warehouse_stocks.merchant_id', MerchantContext::id())
            ->where('warehouses.merchant_id', MerchantContext::id())
            ->where('branch_warehouses.merchant_id', MerchantContext::id())
            ->where('branches.merchant_id', MerchantContext::id())
            ->where('product_variants.merchant_id', MerchantContext::id())
            ->where('products.merchant_id', MerchantContext::id())
            ->where('products.type', 'product')
            ->where('products.track_stock', true)
            ->whereNull('warehouses.deleted_at')
            ->whereNull('branches.deleted_at')
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->select([
                'warehouse_stocks.id',
                'products.id as product_id',
                'products.name as product_name',
                'product_variants.id as product_variant_id',
                'product_variants.name as variant_name',
                'product_variants.sku',
                'branches.id as branch_id',
                'branches.name as branch_name',
                'warehouses.id as warehouse_id',
                'warehouses.name as warehouse_name',
                'warehouse_stocks.quantity',
                'warehouse_stocks.avg_cost',
                'warehouse_stocks.last_mutated_at',
            ]);

        return ServerSideTable::paginate($query, $config, $request);
    }

    public function getFilterOptions(): array
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        $merchantId = MerchantContext::id();
        $branches = Branch::query()
            ->where('is_active', true)
            ->whereHas('warehouses', fn ($query) => $query
                ->where('warehouses.is_active', true)
                ->where('branch_warehouses.merchant_id', $merchantId))
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();

        $warehouses = Warehouse::query()
            ->join('branch_warehouses', 'branch_warehouses.warehouse_id', '=', 'warehouses.id')
            ->join('branches', 'branches.id', '=', 'branch_warehouses.branch_id')
            ->where('branch_warehouses.merchant_id', $merchantId)
            ->where('branches.merchant_id', $merchantId)
            ->where('branches.is_active', true)
            ->whereNull('branches.deleted_at')
            ->where('warehouses.is_active', true)
            ->where('branch_warehouses.is_active', true)
            ->select([
                'warehouses.id',
                'warehouses.name',
                'warehouses.code',
                'branches.id as branch_id',
            ])
            ->orderBy('warehouses.name')
            ->get();

        return compact('branches', 'warehouses');
    }
}
