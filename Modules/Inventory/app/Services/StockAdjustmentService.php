<?php

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Inventory\Models\StockAdjustment;
use Spatie\QueryBuilder\AllowedFilter;

class StockAdjustmentService
{
    public function __construct(
        protected StockAdjustment $model,
        protected DocumentNumberService $documentNumberService,
        protected StockService $stockService,
        protected WarehouseStockService $warehouseStockService,
    ) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $merchantId = $this->requireMerchant();
        $branchFilter = AllowedFilter::callback('branch_id', function (Builder $query, mixed $value) use ($merchantId) {
            $query->whereExists(function ($subquery) use ($merchantId, $value) {
                $subquery->selectRaw('1')
                    ->from('branch_warehouses as filter_bw')
                    ->join('branches as filter_branch', 'filter_branch.id', '=', 'filter_bw.branch_id')
                    ->whereColumn('filter_bw.warehouse_id', 'stock_adjustments.warehouse_id')
                    ->where('filter_bw.merchant_id', $merchantId)
                    ->where('filter_branch.merchant_id', $merchantId)
                    ->whereNull('filter_branch.deleted_at')
                    ->where('filter_branch.id', $value);
            });
        });

        $config = new DataTableConfig(
            allowedFields: [
                'id', 'document_number', 'branch_id', 'branch_name', 'warehouse_id', 'warehouse_name',
                'adjustment_date', 'reason', 'status', 'notes', 'items_count', 'created_at',
            ],
            allowedFilters: [
                $branchFilter,
                AllowedFilter::exact('warehouse_id', 'stock_adjustments.warehouse_id'),
                AllowedFilter::exact('status', 'stock_adjustments.status'),
            ],
            allowedSorts: ['stock_adjustments.document_number', 'stock_adjustments.adjustment_date', 'stock_adjustments.status', 'warehouses.name'],
            searchableColumns: ['stock_adjustments.document_number', 'stock_adjustments.reason', 'stock_adjustments.notes', 'warehouses.name'],
            defaultSort: '-stock_adjustments.adjustment_date',
            withCount: ['items'],
        );

        $branchBase = DB::table('branch_warehouses as list_bw')
            ->join('branches as list_branch', 'list_branch.id', '=', 'list_bw.branch_id')
            ->whereColumn('list_bw.warehouse_id', 'stock_adjustments.warehouse_id')
            ->where('list_bw.merchant_id', $merchantId)
            ->where('list_branch.merchant_id', $merchantId)
            ->whereNull('list_branch.deleted_at');

        $branchId = (clone $branchBase)->select('list_branch.id')->orderBy('list_branch.name')->limit(1);
        $branchName = (clone $branchBase)->select('list_branch.name')->orderBy('list_branch.name')->limit(1);

        $query = $this->model->newQuery()
            ->join('warehouses', 'warehouses.id', '=', 'stock_adjustments.warehouse_id')
            ->where('stock_adjustments.merchant_id', $merchantId)
            ->where('warehouses.merchant_id', $merchantId)
            ->whereNull('warehouses.deleted_at')
            ->select([
                'stock_adjustments.id',
                'stock_adjustments.document_number',
                'stock_adjustments.warehouse_id',
                'warehouses.name as warehouse_name',
                'stock_adjustments.adjustment_date',
                'stock_adjustments.reason',
                'stock_adjustments.status',
                'stock_adjustments.notes',
                'stock_adjustments.created_at',
            ])
            ->selectSub($branchId, 'branch_id')
            ->selectSub($branchName, 'branch_name');

        return ServerSideTable::paginate($query, $config, $request);
    }

    public function getFilterOptions(): array
    {
        return [
            ...$this->warehouseStockService->getFilterOptions(),
            'reasons' => $this->adjustmentReasons(),
        ];
    }

    private function adjustmentReasons(): array
    {
        return [
            ['value' => 'damaged', 'label' => 'Rusak'],
            ['value' => 'expired', 'label' => 'Kedaluwarsa'],
            ['value' => 'lost', 'label' => 'Hilang'],
            ['value' => 'found', 'label' => 'Ditemukan'],
            ['value' => 'correction', 'label' => 'Koreksi'],
            ['value' => 'opening_stock', 'label' => 'Saldo Awal'],
            ['value' => 'other', 'label' => 'Lainnya'],
        ];
    }

    public function searchProductOptions(
        int $warehouseId,
        string $reason,
        string $search,
        int $perPage = 15,
        ?int $selectedVariantId = null,
    ): array
    {
        $merchantId = $this->requireMerchant();
        $query = DB::table('products as p')
            ->join('product_variants as pv', 'pv.product_id', '=', 'p.id')
            ->where('p.merchant_id', $merchantId)
            ->where('pv.merchant_id', $merchantId)
            ->where('p.type', 'product')
            ->where('p.track_stock', true)
            ->where('p.is_active', true)
            ->where('pv.is_active', true)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->whereExists(function ($warehouse) use ($merchantId, $warehouseId) {
                $warehouse->selectRaw('1')
                    ->from('warehouses as w')
                    ->join('branch_warehouses as bw', 'bw.warehouse_id', '=', 'w.id')
                    ->join('branches as b', 'b.id', '=', 'bw.branch_id')
                    ->where('w.id', $warehouseId)
                    ->where('w.merchant_id', $merchantId)
                    ->where('bw.merchant_id', $merchantId)
                    ->where('b.merchant_id', $merchantId)
                    ->where('w.is_active', true)
                    ->where('bw.is_active', true)
                    ->where('b.is_active', true)
                    ->whereNull('w.deleted_at')
                    ->whereNull('b.deleted_at');
            });

        if ($selectedVariantId !== null) {
            $query->where('pv.id', $selectedVariantId);
        } elseif (! in_array($reason, ['found', 'opening_stock'], true)) {
            $query->whereExists(function ($stock) use ($merchantId, $warehouseId) {
                $stock->selectRaw('1')
                    ->from('warehouse_stocks as ws')
                    ->whereColumn('ws.product_variant_id', 'pv.id')
                    ->where('ws.merchant_id', $merchantId)
                    ->where('ws.warehouse_id', $warehouseId);
            });
        }

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('p.name', 'like', "%{$search}%")
                    ->orWhere('pv.name', 'like', "%{$search}%")
                    ->orWhere('pv.sku', 'like', "%{$search}%");
            });
        }

        $variants = $query
            ->select([
                'pv.id as product_variant_id',
                'p.name as product_name',
                'pv.name as variant_name',
                'pv.sku',
            ])
            ->orderBy('p.name')
            ->orderBy('pv.sku')
            ->limit(min(max($perPage, 1), 50))
            ->get();

        if ($variants->isEmpty()) {
            return [];
        }

        $variantIds = $variants->pluck('product_variant_id')->all();
        $unitsByVariant = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('product_variant_units as pvu', 'pvu.product_variant_id', '=', 'pv.id')
            ->join('product_unit_conversions as puc', function ($join) {
                $join->on('puc.product_id', '=', 'p.id')
                    ->on('puc.unit_id', '=', 'pvu.unit_id');
            })
            ->join('units as u', 'u.id', '=', 'pvu.unit_id')
            ->whereIn('pv.id', $variantIds)
            ->where('p.merchant_id', $merchantId)
            ->where('pv.merchant_id', $merchantId)
            ->where('pvu.merchant_id', $merchantId)
            ->where('puc.merchant_id', $merchantId)
            ->where(function ($builder) use ($merchantId) {
                $builder->whereNull('u.merchant_id')->orWhere('u.merchant_id', $merchantId);
            })
            ->where('pvu.is_active', true)
            ->where('u.is_active', true)
            ->whereNull('pvu.deleted_at')
            ->whereNull('u.deleted_at')
            ->select([
                'pv.id as product_variant_id',
                'u.id as unit_id',
                'u.name as unit_name',
                'u.code as unit_code',
                'puc.conversion_value as conversion_rate',
                'puc.is_base',
                'pvu.cost as unit_cost',
            ])
            ->orderByDesc('puc.is_base')
            ->orderBy('u.name')
            ->get()
            ->groupBy('product_variant_id');

        $stocksByVariant = DB::table('warehouse_stocks')
            ->where('merchant_id', $merchantId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $variantIds)
            ->pluck('quantity', 'product_variant_id');

        return $variants->map(function ($variant) use ($unitsByVariant, $stocksByVariant) {
            return [
                'product_variant_id' => (int) $variant->product_variant_id,
                'product_name' => $variant->product_name,
                'variant_name' => $variant->variant_name,
                'sku' => $variant->sku,
                'label' => $variant->product_name
                    . ($variant->variant_name ? ' / ' . $variant->variant_name : '')
                    . ' · ' . $variant->sku,
                'stock_quantity' => (float) ($stocksByVariant[$variant->product_variant_id] ?? 0),
                'units' => ($unitsByVariant[$variant->product_variant_id] ?? collect())
                    ->map(fn ($unit) => [
                        'unit_id' => (int) $unit->unit_id,
                        'unit_name' => $unit->unit_name,
                        'unit_code' => $unit->unit_code,
                        'conversion_rate' => $unit->conversion_rate,
                        'is_base' => (bool) $unit->is_base,
                        'unit_cost' => $unit->unit_cost,
                    ])
                    ->values()
                    ->all(),
            ];
        })->all();
    }

    public function findForCurrentMerchant(int $id): array
    {
        $merchantId = $this->requireMerchant();
        $adjustment = $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->findOrFail($id);

        $warehouse = DB::table('warehouses')
            ->where('merchant_id', $merchantId)
            ->where('id', $adjustment->warehouse_id)
            ->first(['id', 'name']);

        $branch = DB::table('branch_warehouses as bw')
            ->join('branches as b', 'b.id', '=', 'bw.branch_id')
            ->where('bw.merchant_id', $merchantId)
            ->where('b.merchant_id', $merchantId)
            ->where('bw.warehouse_id', $adjustment->warehouse_id)
            ->whereNull('b.deleted_at')
            ->orderBy('b.name')
            ->first(['b.id', 'b.name']);

        $items = DB::table('stock_adjustment_items as item')
            ->join('product_variants as pv', 'pv.id', '=', 'item.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('units as u', 'u.id', '=', 'item.unit_id')
            ->where('item.merchant_id', $merchantId)
            ->where('item.stock_adjustment_id', $adjustment->id)
            ->where('pv.merchant_id', $merchantId)
            ->where('p.merchant_id', $merchantId)
            ->select([
                'item.id', 'p.id as product_id', 'p.name as product_name',
                'pv.id as product_variant_id', 'pv.name as variant_name', 'pv.sku',
                'u.id as unit_id', 'u.name as unit_name', 'u.code as unit_code',
                'item.unit_quantity', 'item.conversion_rate', 'item.base_quantity',
                'item.unit_cost', 'item.notes',
            ])
            ->orderBy('item.id')
            ->get();

        return [
            'id' => $adjustment->id,
            'document_number' => $adjustment->document_number,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->name,
            'warehouse_id' => $adjustment->warehouse_id,
            'warehouse_name' => $warehouse?->name,
            'adjustment_date' => $adjustment->adjustment_date,
            'reason' => $adjustment->reason,
            'status' => $adjustment->status,
            'notes' => $adjustment->notes,
            'posted_at' => $adjustment->posted_at,
            'posted_by' => $adjustment->posted_by,
            'voided_at' => $adjustment->voided_at,
            'voided_by' => $adjustment->voided_by,
            'void_reason' => $adjustment->void_reason,
            'created_at' => $adjustment->created_at,
            'updated_at' => $adjustment->updated_at,
            'items' => $items,
        ];
    }

    public function create(array $data): array
    {
        $merchantId = $this->requireMerchant();

        return DB::transaction(function () use ($data, $merchantId) {
            $this->assertWarehouse($data['warehouse_id'], $merchantId);
            $adjustment = $this->model->newQuery()->create([
                'merchant_id' => $merchantId,
                'document_number' => $this->documentNumberService->nextStockAdjustmentNumber(),
                'warehouse_id' => $data['warehouse_id'],
                'adjustment_date' => $data['adjustment_date'],
                'reason' => $data['reason'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->guard('merchant')->id(),
                'updated_by' => auth()->guard('merchant')->id(),
            ]);

            $this->replaceItems($adjustment, $data['items'], $merchantId);

            return $this->findForCurrentMerchant($adjustment->id);
        });
    }

    public function update(int $id, array $data): array
    {
        $merchantId = $this->requireMerchant();

        return DB::transaction(function () use ($id, $data, $merchantId) {
            $adjustment = $this->lockAdjustment($id, $merchantId);
            $this->assertDraft($adjustment);
            $this->assertWarehouse($data['warehouse_id'], $merchantId);

            $adjustment->fill([
                'warehouse_id' => $data['warehouse_id'],
                'adjustment_date' => $data['adjustment_date'],
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'updated_by' => auth()->guard('merchant')->id(),
            ])->save();

            DB::table('stock_adjustment_items')
                ->where('merchant_id', $merchantId)
                ->where('stock_adjustment_id', $adjustment->id)
                ->delete();
            $this->replaceItems($adjustment, $data['items'], $merchantId);

            return $this->findForCurrentMerchant($adjustment->id);
        });
    }

    public function delete(int $id): void
    {
        $merchantId = $this->requireMerchant();
        DB::transaction(function () use ($id, $merchantId) {
            $adjustment = $this->lockAdjustment($id, $merchantId);
            $this->assertDraft($adjustment);
            $adjustment->delete();
        });
    }

    public function post(int $id): array
    {
        $merchantId = $this->requireMerchant();

        return DB::transaction(function () use ($id, $merchantId) {
            $adjustment = $this->lockAdjustment($id, $merchantId);
            $this->assertDraft($adjustment);
            $items = DB::table('stock_adjustment_items')
                ->where('merchant_id', $merchantId)
                ->where('stock_adjustment_id', $adjustment->id)
                ->orderBy('product_variant_id', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Dokumen adjustment harus memiliki minimal satu item.']);
            }

            $mutatedAt = \Illuminate\Support\Carbon::parse($adjustment->adjustment_date)->startOfDay();
            foreach ($items as $item) {
                $baseUnitCost = $item->unit_cost === null
                    ? null
                    : ((float) $item->unit_cost / (float) $item->conversion_rate);

                $this->stockService->recordMutation(
                    warehouseId: (int) $adjustment->warehouse_id,
                    productVariantId: (int) $item->product_variant_id,
                    baseQuantity: (float) $item->base_quantity,
                    mutationType: $adjustment->reason === 'opening_stock' ? 'opening_stock' : 'stock_adjustment',
                    incomingUnitCost: (float) $item->base_quantity > 0 ? $baseUnitCost : null,
                    referenceType: 'stock_adjustment',
                    referenceId: (int) $adjustment->id,
                    referenceNumber: $adjustment->document_number,
                    notes: $item->notes ?: $adjustment->notes,
                    mutatedAt: $mutatedAt,
                );
            }

            $adjustment->status = 'posted';
            $adjustment->posted_at = now();
            $adjustment->posted_by = auth()->guard('merchant')->id();
            $adjustment->updated_by = auth()->guard('merchant')->id();
            $adjustment->save();

            return $this->findForCurrentMerchant($adjustment->id);
        });
    }

    public function void(int $id, string $reason): array
    {
        $merchantId = $this->requireMerchant();

        return DB::transaction(function () use ($id, $reason, $merchantId) {
            $adjustment = $this->lockAdjustment($id, $merchantId);
            if ($adjustment->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Hanya adjustment berstatus posted yang dapat dibatalkan.']);
            }

            $mutations = DB::table('stock_mutations')
                ->where('merchant_id', $merchantId)
                ->where('reference_type', 'stock_adjustment')
                ->where('reference_id', $adjustment->id)
                ->whereIn('mutation_type', ['stock_adjustment', 'opening_stock'])
                ->orderBy('product_variant_id', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($mutations->isEmpty()) {
                throw ValidationException::withMessages(['status' => 'Mutasi sumber tidak ditemukan; dokumen tidak dapat dibatalkan.']);
            }

            foreach ($mutations as $mutation) {
                $reverseQuantity = -((float) $mutation->quantity);
                $this->stockService->recordMutation(
                    warehouseId: (int) $mutation->warehouse_id,
                    productVariantId: (int) $mutation->product_variant_id,
                    baseQuantity: $reverseQuantity,
                    mutationType: 'stock_adjustment_void',
                    incomingUnitCost: $reverseQuantity > 0 ? (float) $mutation->unit_cost : null,
                    referenceType: 'stock_adjustment',
                    referenceId: (int) $adjustment->id,
                    referenceNumber: $adjustment->document_number,
                    notes: $reason,
                );
            }

            $adjustment->status = 'voided';
            $adjustment->voided_at = now();
            $adjustment->voided_by = auth()->guard('merchant')->id();
            $adjustment->void_reason = $reason;
            $adjustment->updated_by = auth()->guard('merchant')->id();
            $adjustment->save();

            return $this->findForCurrentMerchant($adjustment->id);
        });
    }

    private function replaceItems(StockAdjustment $adjustment, array $items, int $merchantId): void
    {
        $now = now();
        $rows = [];
        foreach ($items as $index => $item) {
            if (
                ! in_array($adjustment->reason, ['opening_stock', 'found'], true)
                && ! DB::table('warehouse_stocks')
                    ->where('merchant_id', $merchantId)
                    ->where('warehouse_id', $adjustment->warehouse_id)
                    ->where('product_variant_id', $item['product_variant_id'])
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => 'Produk ini belum memiliki stok pada gudang yang dipilih.',
                ]);
            }

            $variantUnit = DB::table('product_variant_units as pvu')
                ->join('product_variants as pv', 'pv.id', '=', 'pvu.product_variant_id')
                ->join('products as p', 'p.id', '=', 'pv.product_id')
                ->join('product_unit_conversions as puc', function ($join) {
                    $join->on('puc.product_id', '=', 'p.id')
                        ->on('puc.unit_id', '=', 'pvu.unit_id');
                })
                ->join('units as u', 'u.id', '=', 'pvu.unit_id')
                ->where('pvu.merchant_id', $merchantId)
                ->where('pvu.product_variant_id', $item['product_variant_id'])
                ->where('pvu.unit_id', $item['unit_id'])
                ->where('pv.merchant_id', $merchantId)
                ->where('p.merchant_id', $merchantId)
                ->where('puc.merchant_id', $merchantId)
                ->where(function ($query) use ($merchantId) {
                    $query->whereNull('u.merchant_id')->orWhere('u.merchant_id', $merchantId);
                })
                ->where('p.type', 'product')
                ->where('p.track_stock', true)
                ->where('p.is_active', true)
                ->where('pv.is_active', true)
                ->where('pvu.is_active', true)
                ->where('u.is_active', true)
                ->whereNull('p.deleted_at')
                ->whereNull('pv.deleted_at')
                ->whereNull('pvu.deleted_at')
                ->whereNull('u.deleted_at')
                ->first(['puc.conversion_value']);

            if (! $variantUnit || (float) $variantUnit->conversion_value <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_id" => 'Satuan tidak tersedia untuk varian produk yang dipilih.',
                ]);
            }

            $conversionRate = (float) $variantUnit->conversion_value;
            $unitQuantity = (float) $item['unit_quantity'];
            $baseQuantity = round($unitQuantity * $conversionRate, 4);
            if ($baseQuantity == 0.0) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_quantity" => 'Jumlah setelah konversi ke satuan dasar tidak boleh nol.',
                ]);
            }

            $rows[] = [
                'merchant_id' => $merchantId,
                'stock_adjustment_id' => $adjustment->id,
                'product_variant_id' => $item['product_variant_id'],
                'unit_id' => $item['unit_id'],
                'unit_quantity' => $unitQuantity,
                'conversion_rate' => $conversionRate,
                'base_quantity' => $baseQuantity,
                'unit_cost' => $item['unit_cost'] ?? null,
                'notes' => $item['notes'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('stock_adjustment_items')->insert($rows);
    }

    private function assertWarehouse(int|string $warehouseId, int $merchantId): void
    {
        $exists = DB::table('warehouses as w')
            ->join('branch_warehouses as bw', 'bw.warehouse_id', '=', 'w.id')
            ->join('branches as b', 'b.id', '=', 'bw.branch_id')
            ->where('w.id', $warehouseId)
            ->where('w.merchant_id', $merchantId)
            ->where('bw.merchant_id', $merchantId)
            ->where('b.merchant_id', $merchantId)
            ->where('w.is_active', true)
            ->where('bw.is_active', true)
            ->where('b.is_active', true)
            ->whereNull('w.deleted_at')
            ->whereNull('b.deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['warehouse_id' => 'Gudang tidak aktif atau bukan bagian dari merchant ini.']);
        }
    }

    private function lockAdjustment(int $id, int $merchantId): StockAdjustment
    {
        return $this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->lockForUpdate()
            ->findOrFail($id);
    }

    private function assertDraft(StockAdjustment $adjustment): void
    {
        if ($adjustment->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Dokumen hanya dapat diubah saat berstatus draft.']);
        }
    }

    private function requireMerchant(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        return (int) MerchantContext::id();
    }
}
