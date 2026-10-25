<?php

namespace Modules\Inventory\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Inventory\Models\StockMutation;
use Spatie\QueryBuilder\AllowedFilter;

class StockMutationService
{
    public function __construct(
        protected StockMutation $model,
        protected WarehouseStockService $warehouseStockService,
    ) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        $config = new DataTableConfig(
            allowedFields: [
                'id', 'product_id', 'product_name', 'product_variant_id', 'variant_name', 'sku',
                'branch_id', 'branch_name', 'warehouse_id', 'warehouse_name', 'mutation_type',
                'quantity', 'quantity_before', 'quantity_after', 'unit_cost', 'total_cost',
                'avg_cost_after', 'reference_type', 'reference_number', 'notes', 'mutated_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('branch_id', 'branches.id'),
                AllowedFilter::exact('warehouse_id', 'warehouses.id'),
            ],
            allowedSorts: [
                'products.name', 'product_variants.sku', 'stock_mutations.mutation_type',
                'stock_mutations.quantity', 'stock_mutations.mutated_at',
            ],
            searchableColumns: [
                'products.name', 'product_variants.name', 'product_variants.sku',
                'stock_mutations.reference_number', 'stock_mutations.notes',
            ],
            defaultSort: '-stock_mutations.mutated_at',
        );

        $merchantId = MerchantContext::id();
        $query = $this->model->newQuery()
            ->join('warehouses', 'warehouses.id', '=', 'stock_mutations.warehouse_id')
            ->join('branch_warehouses', 'branch_warehouses.warehouse_id', '=', 'warehouses.id')
            ->join('branches', 'branches.id', '=', 'branch_warehouses.branch_id')
            ->join('product_variants', 'product_variants.id', '=', 'stock_mutations.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('stock_mutations.merchant_id', $merchantId)
            ->where('warehouses.merchant_id', $merchantId)
            ->where('branch_warehouses.merchant_id', $merchantId)
            ->where('branches.merchant_id', $merchantId)
            ->where('product_variants.merchant_id', $merchantId)
            ->where('products.merchant_id', $merchantId)
            ->where('products.type', 'product')
            ->where('products.track_stock', true)
            ->whereNull('warehouses.deleted_at')
            ->whereNull('branches.deleted_at')
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->select([
                'stock_mutations.id',
                'products.id as product_id',
                'products.name as product_name',
                'product_variants.id as product_variant_id',
                'product_variants.name as variant_name',
                'product_variants.sku',
                'branches.id as branch_id',
                'branches.name as branch_name',
                'warehouses.id as warehouse_id',
                'warehouses.name as warehouse_name',
                'stock_mutations.mutation_type',
                'stock_mutations.quantity',
                'stock_mutations.quantity_before',
                'stock_mutations.quantity_after',
                'stock_mutations.unit_cost',
                'stock_mutations.total_cost',
                'stock_mutations.avg_cost_after',
                'stock_mutations.reference_type',
                'stock_mutations.reference_number',
                'stock_mutations.notes',
                'stock_mutations.mutated_at',
            ]);

        return ServerSideTable::paginate($query, $config, $request);
    }

    public function getFilterOptions(): array
    {
        return $this->warehouseStockService->getFilterOptions();
    }
}
