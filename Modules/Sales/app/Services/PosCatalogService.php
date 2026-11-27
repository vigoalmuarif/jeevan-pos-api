<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Services\PriceResolverService;
use Modules\Sales\Enums\SalesOrderType;
use Modules\Sales\Enums\SalesPaymentMethod;

class PosCatalogService
{
    public function __construct(
        private readonly PriceResolverService $priceResolver,
        private readonly PosConfigResolver $configResolver,
    ) {}

    /**
     * Resolve active branch and its primary warehouse for POS operations.
     *
     * @return array{branch_id:int,branch_name:string,warehouse_id:int,warehouse_name:string}
     */
    public function resolveBranchWarehouse(int $branchId): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $branch = DB::table('branches')
            ->where('id', $branchId)
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang tidak ditemukan atau tidak aktif.',
            ]);
        }

        $warehouse = null;

        if (! empty($branch->primary_warehouse_id)) {
            $warehouse = DB::table('warehouses')
                ->where('id', (int) $branch->primary_warehouse_id)
                ->where('merchant_id', $merchantId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first(['id', 'name']);
        }

        if ($warehouse === null && Schema::hasTable('branch_warehouses')) {
            $warehouse = DB::table('branch_warehouses')
                ->join('warehouses', 'warehouses.id', '=', 'branch_warehouses.warehouse_id')
                ->where('branch_warehouses.merchant_id', $merchantId)
                ->where('branch_warehouses.branch_id', $branchId)
                ->where('branch_warehouses.is_active', true)
                ->where('warehouses.merchant_id', $merchantId)
                ->where('warehouses.is_active', true)
                ->whereNull('warehouses.deleted_at')
                ->orderByDesc('branch_warehouses.is_primary')
                ->orderBy('branch_warehouses.id')
                ->select('warehouses.id', 'warehouses.name')
                ->first();
        }

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang ini belum memiliki gudang utama aktif untuk transaksi POS.',
            ]);
        }

        return [
            'branch_id' => (int) $branch->id,
            'branch_name' => (string) $branch->name,
            'warehouse_id' => (int) $warehouse->id,
            'warehouse_name' => (string) $warehouse->name,
        ];
    }

    /**
     * Ambil daftar katalog produk siap jual pada cabang aktif beserta stok gudang utama & harga jual.
     *
     * @return array<string, mixed>
     */
    public function catalog(array $filters): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $branchId = (int) $filters['branch_id'];
        $branchInfo = $this->resolveBranchWarehouse($branchId);
        $warehouseId = $branchInfo['warehouse_id'];
        $priceListId = ! empty($filters['price_list_id']) ? (int) $filters['price_list_id'] : null;

        if ($priceListId === null && ! empty($filters['order_type'])) {
            $branchConfig = $this->configResolver->resolveForBranch($branchId);
            $mappedId = $branchConfig['channel_price_lists'][(string) $filters['order_type']] ?? null;
            if ($mappedId !== null && (int) $mappedId > 0) {
                $priceListId = (int) $mappedId;
            }
        }

        $query = ProductVariant::query()
            ->where('product_variants.merchant_id', $merchantId)
            ->where('product_variants.is_active', true)
            ->whereHas('product', function ($q) use ($merchantId, $filters) {
                $q->where('merchant_id', $merchantId)
                    ->where('is_active', true)
                    ->where('can_be_sold', true);

                if (! empty($filters['category_id'])) {
                    $categoryId = (int) $filters['category_id'];
                    $q->whereHas('categories', fn ($c) => $c->where('categories.id', $categoryId));
                }
            })
            ->whereHas('units', fn ($u) => $u->where('merchant_id', $merchantId)->where('is_active', true));

        // Hanya tampilkan produk/varian yang di-assign ke cabang aktif melalui branch_active_products.
        $query->whereExists(function ($sub) use ($merchantId, $branchId) {
            $sub->select(DB::raw(1))
                ->from('branch_active_products')
                ->whereColumn('branch_active_products.product_variant_id', 'product_variants.id')
                ->where('branch_active_products.merchant_id', $merchantId)
                ->where('branch_active_products.branch_id', $branchId);
        });

        if (! empty($filters['barcode'])) {
            $barcode = mb_strtolower(trim((string) $filters['barcode']));
            $query->where(function ($q) use ($barcode, $merchantId) {
                $q->whereRaw('LOWER(COALESCE(product_variants.sku, \'\')) = ?', [$barcode])
                    ->orWhereHas('units', fn ($u) => $u
                        ->where('merchant_id', $merchantId)
                        ->where('is_active', true)
                        ->whereRaw('LOWER(COALESCE(barcode, \'\')) = ?', [$barcode]));
            });
        } elseif (! empty($filters['search'])) {
            $search = mb_strtolower(trim((string) $filters['search']));
            $like = "%{$search}%";
            $query->where(function ($q) use ($like, $merchantId) {
                $q->whereRaw('LOWER(COALESCE(product_variants.name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(product_variants.sku, \'\')) LIKE ?', [$like])
                    ->orWhereHas('product', fn ($p) => $p->whereRaw('LOWER(products.name) LIKE ?', [$like]))
                    ->orWhereHas('units', fn ($u) => $u
                        ->where('merchant_id', $merchantId)
                        ->where('is_active', true)
                        ->whereRaw('LOWER(COALESCE(barcode, \'\')) LIKE ?', [$like]));
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 40), 1), 100);
        $paginator = $query
            ->with([
                'product:id,name,track_stock,can_be_sold,is_taxable,tax_rate',
                'product.categories:id,name',
                'product.images:id,product_id,path,sort_order',
                'images:id,product_variant_id,path,sort_order',
                'units' => fn ($u) => $u->where('merchant_id', $merchantId)->where('is_active', true)->orderByDesc('is_base')->orderBy('id'),
                'units.unit:id,name,code',
            ])
            ->orderBy('product_variants.id')
            ->paginate($perPage);

        $variantIds = $paginator->getCollection()->pluck('id')->all();
        $stocksByVariant = empty($variantIds)
            ? collect()
            : DB::table('warehouse_stocks')
                ->where('merchant_id', $merchantId)
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_variant_id', $variantIds)
                ->pluck('quantity', 'product_variant_id');

        $allUnits = $paginator->getCollection()->flatMap(fn (ProductVariant $variant) => $variant->units);
        $resolvedPrices = $this->priceResolver->resolveMany(
            units: $allUnits,
            quantity: 1.0,
            priceListId: $priceListId,
            branchId: $branchId,
            skipScopeValidation: $priceListId === null,
        );

        // Batch-load modifier groups & options for all products on this page (ZERO N+1 queries).
        $productIds = $paginator->getCollection()->pluck('product_id')->unique()->values()->all();
        $modifiersByProduct = $this->batchLoadProductModifiers($merchantId, $productIds);

        // Batch-load Quick 86 (Sold Out today) status for all variants on this page (1 query, ZERO N+1).
        $soldOutVariantMap = [];
        if (! empty($variantIds) && Schema::hasTable('branch_sold_out_items')) {
            $today = now()->toDateString();
            $soldOutIds = DB::table('branch_sold_out_items')
                ->where('merchant_id', $merchantId)
                ->where('branch_id', $branchId)
                ->whereIn('product_variant_id', $variantIds)
                ->where(function ($q) use ($today) {
                    $q->whereNull('sold_out_date')
                        ->orWhere('sold_out_date', '>=', $today);
                })
                ->pluck('product_variant_id')
                ->all();
            foreach ($soldOutIds as $sid) {
                $soldOutVariantMap[(int) $sid] = true;
            }
        }

        $items = $paginator->getCollection()->map(function (ProductVariant $variant) use ($stocksByVariant, $resolvedPrices, $modifiersByProduct, $soldOutVariantMap) {
            $onHand = round((float) ($stocksByVariant[$variant->id] ?? 0), 4);
            $product = $variant->product;
            $imagePath = $variant->images->first()?->path ?? $product?->images?->first()?->path;

            $units = $variant->units->map(function ($vu) use ($resolvedPrices) {
                $resolved = $resolvedPrices[(int) $vu->id] ?? [
                    'price' => (float) $vu->default_price,
                    'source' => 'default_price',
                ];

                return [
                    'product_variant_unit_id' => (int) $vu->id,
                    'unit_id' => (int) $vu->unit_id,
                    'unit_name' => (string) ($vu->unit?->name ?? 'Pcs'),
                    'unit_code' => $vu->unit?->code,
                    'conversion_value' => (float) $vu->conversion_value,
                    'is_base' => (bool) $vu->is_base,
                    'barcode' => $vu->barcode,
                    'default_price' => (float) $vu->default_price,
                    'resolved_price' => (float) $resolved['price'],
                    'pricing_source' => $resolved['source'],
                ];
            })->values()->all();

            return [
                'product_id' => (int) $variant->product_id,
                'product_variant_id' => (int) $variant->id,
                'product_name' => (string) ($product?->name ?? ''),
                'variant_name' => $variant->name,
                'sku' => $variant->sku,
                'track_stock' => (bool) ($product?->track_stock ?? true),
                'is_sold_out' => (bool) ($soldOutVariantMap[(int) $variant->id] ?? false),
                'is_taxable' => (bool) ($product?->is_taxable ?? false),
                'tax_rate' => (float) ($product?->tax_rate ?? 0),
                'on_hand_stock' => $onHand,
                'image_url' => $imagePath ? asset('storage/'.ltrim((string) $imagePath, '/')) : null,
                'categories' => $product?->categories?->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->values()->all() ?? [],
                'units' => $units,
                'modifier_groups' => $modifiersByProduct[(int) $variant->product_id] ?? [],
            ];
        })->values()->all();

        return [
            'branch' => $branchInfo,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Ambil peta stok gudang utama cabang untuk daftar product_variant_id secara ringan (tanpa memuat katalog penuh).
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, float>
     */
    public function stocks(int $branchId, array $variantIds): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $branchInfo = $this->resolveBranchWarehouse($branchId);
        $warehouseId = $branchInfo['warehouse_id'];

        $ids = array_values(array_unique(array_filter(array_map('intval', $variantIds), fn (int $id) => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $stocksByVariant = DB::table('warehouse_stocks')
            ->where('merchant_id', $merchantId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_variant_id', $ids)
            ->pluck('quantity', 'product_variant_id');

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = round((float) ($stocksByVariant[$id] ?? 0), 4);
        }

        return $result;
    }

    /**
     * Resolusi harga jual per satuan untuk layar kasir POS.
     *
     * @return array<string, mixed>
     */
    public function resolvePrice(int $variantUnitId, float $quantity, ?int $priceListId = null, ?int $branchId = null): array
    {
        return $this->priceResolver->resolve($variantUnitId, $quantity, $priceListId, $branchId);
    }

    /**
     * Quick 86: Daftar produk aktif cabang beserta status Habis Hari Ini (untuk POS & KDS).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSoldOutMenu(int $branchId, ?string $search = null, bool $onlySoldOut = false): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $this->resolveBranchWarehouse($branchId);
        $today = now()->toDateString();

        $query = DB::table('branch_active_products as bap')
            ->join('product_variants as pv', 'pv.id', '=', 'bap.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('branch_sold_out_items as bso', function ($join) use ($merchantId, $branchId, $today) {
                $join->on('bso.product_variant_id', '=', 'pv.id')
                    ->where('bso.merchant_id', '=', $merchantId)
                    ->where('bso.branch_id', '=', $branchId)
                    ->where(function ($q) use ($today) {
                        $q->whereNull('bso.sold_out_date')
                            ->orWhere('bso.sold_out_date', '>=', $today);
                    });
            })
            ->where('bap.merchant_id', $merchantId)
            ->where('bap.branch_id', $branchId)
            ->where('pv.merchant_id', $merchantId)
            ->where('p.merchant_id', $merchantId)
            ->whereNull('pv.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('pv.is_active', true)
            ->where('p.is_active', true)
            ->where('p.can_be_sold', true)
            ->select([
                'pv.id as product_variant_id',
                'p.id as product_id',
                'p.name as product_name',
                'pv.name as variant_name',
                'pv.sku',
                'p.track_stock',
                'bso.id as sold_out_id',
                'bso.sold_out_date',
                'bso.reason',
            ]);

        if ($onlySoldOut) {
            $query->whereNotNull('bso.id');
        }

        if ($search !== null && trim($search) !== '') {
            $like = '%'.mb_strtolower(trim($search)).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(p.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(pv.name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(pv.sku, \'\')) LIKE ?', [$like]);
            });
        }

        return $query
            ->orderByRaw('CASE WHEN bso.id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('p.name')
            ->orderBy('pv.id')
            ->limit(150)
            ->get()
            ->map(fn ($row) => [
                'product_variant_id' => (int) $row->product_variant_id,
                'product_id' => (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                'variant_name' => $row->variant_name,
                'sku' => $row->sku,
                'track_stock' => (bool) $row->track_stock,
                'is_sold_out' => $row->sold_out_id !== null,
                'sold_out_date' => $row->sold_out_date,
                'reason' => $row->reason,
            ])
            ->values()
            ->all();
    }

    /**
     * Quick 86: Toggle status Habis Hari Ini (Sold Out) untuk suatu varian pada cabang tertentu.
     *
     * @return array<string, mixed>
     */
    public function toggleSoldOut(int $branchId, int $productVariantId, bool $isSoldOut, ?string $reason = null): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $this->resolveBranchWarehouse($branchId);

        $variantExists = DB::table('product_variants')
            ->where('id', $productVariantId)
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $variantExists) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Varian produk tidak ditemukan atau bukan milik merchant ini.',
            ]);
        }

        $today = now()->toDateString();
        $now = now();
        $userId = \Illuminate\Support\Facades\Auth::guard('merchant')->id() ?? \Illuminate\Support\Facades\Auth::id();

        if ($isSoldOut) {
            $cleanReason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
            DB::table('branch_sold_out_items')->updateOrInsert(
                [
                    'merchant_id' => $merchantId,
                    'branch_id' => $branchId,
                    'product_variant_id' => $productVariantId,
                ],
                [
                    'sold_out_date' => $today,
                    'reason' => $cleanReason,
                    'marked_by' => $userId,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        } else {
            DB::table('branch_sold_out_items')
                ->where('merchant_id', $merchantId)
                ->where('branch_id', $branchId)
                ->where('product_variant_id', $productVariantId)
                ->delete();
        }

        return [
            'branch_id' => $branchId,
            'product_variant_id' => $productVariantId,
            'is_sold_out' => $isSoldOut,
            'sold_out_date' => $isSoldOut ? $today : null,
        ];
    }

    /**
     * Pencarian pelanggan server-side untuk combobox layar kasir POS.
     *
     * @return array<int, array{id:int,code:?string,name:string,phone:?string}>
     */
    public function searchCustomers(array $filters = []): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $limit = min(max((int) ($filters['limit'] ?? 30), 1), 50);

        $query = DB::table('customers')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at');

        if (! empty($filters['search'])) {
            $search = mb_strtolower(trim((string) $filters['search']));
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$like]);
            });
        }

        return $query
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'phone'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'code' => $c->code,
                'name' => (string) $c->name,
                'phone' => $c->phone,
            ])
            ->values()
            ->all();
    }

    /**
     * Opsi dropdown awal untuk layar POS & riwayat penjualan.
     *
     * @return array<string, mixed>
     */
    public function options(?int $branchId = null): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $branches = DB::table('branches')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get(['id', 'name', 'primary_warehouse_id', 'is_main'])
            ->map(fn ($b) => [
                'id' => (int) $b->id,
                'name' => (string) $b->name,
                'primary_warehouse_id' => $b->primary_warehouse_id ? (int) $b->primary_warehouse_id : null,
                'is_main' => (bool) $b->is_main,
            ])
            ->values()
            ->all();

        $categories = DB::table('categories')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])
            ->values()
            ->all();

        $customers = $this->searchCustomers(['limit' => 30]);

        $paymentMethods = array_map(fn (SalesPaymentMethod $m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'is_cash' => $m->isCash(),
        ], SalesPaymentMethod::cases());

        $orderTypes = array_map(fn (SalesOrderType $t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'applies_service_charge' => $t->appliesServiceCharge(),
        ], SalesOrderType::cases());

        $branchIds = array_map(fn (array $b) => $b['id'], $branches);
        $branchPosConfigs = $this->configResolver->resolveForBranches($branchIds);
        $defaultBranchId = ($branchId !== null && array_key_exists($branchId, $branchPosConfigs))
            ? $branchId
            : ($branchIds[0] ?? null);
        $activePosConfig = $defaultBranchId !== null
            ? $branchPosConfigs[$defaultBranchId]
            : $this->configResolver->resolveForBranch(null);

        $priceLists = [];
        if (Schema::hasTable('price_lists')) {
            $hasMarkupCol = Schema::hasColumn('price_lists', 'default_markup_percent');
            $columns = $hasMarkupCol
                ? ['id', 'code', 'name', 'default_markup_percent']
                : ['id', 'code', 'name'];
            $priceLists = DB::table('price_lists')
                ->where('merchant_id', $merchantId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get($columns)
                ->map(fn ($pl) => [
                    'id' => (int) $pl->id,
                    'code' => $pl->code,
                    'name' => (string) $pl->name,
                    'default_markup_percent' => $hasMarkupCol && $pl->default_markup_percent !== null
                        ? (float) $pl->default_markup_percent
                        : null,
                ])
                ->values()
                ->all();
        }

        return [
            'branches' => $branches,
            'categories' => $categories,
            'customers' => $customers,
            'payment_methods' => $paymentMethods,
            'order_types' => $orderTypes,
            'price_lists' => $priceLists,
            'pos_config' => $activePosConfig,
            'branch_pos_configs' => $branchPosConfigs,
        ];
    }

    /**
     * Batch-load active modifier groups & options keyed by product_id (2 constant queries, 0 N+1).
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function batchLoadProductModifiers(int $merchantId, array $productIds): array
    {
        if (empty($productIds) || ! Schema::hasTable('product_modifier_groups')) {
            return [];
        }

        $links = DB::table('product_modifier_groups')
            ->join('modifier_groups', 'modifier_groups.id', '=', 'product_modifier_groups.modifier_group_id')
            ->where('product_modifier_groups.merchant_id', $merchantId)
            ->whereIn('product_modifier_groups.product_id', $productIds)
            ->where('modifier_groups.merchant_id', $merchantId)
            ->where('modifier_groups.is_active', true)
            ->whereNull('modifier_groups.deleted_at')
            ->orderBy('product_modifier_groups.sort_order')
            ->orderBy('modifier_groups.sort_order')
            ->orderBy('modifier_groups.id')
            ->select([
                'product_modifier_groups.product_id',
                'modifier_groups.id as group_id',
                'modifier_groups.name',
                'modifier_groups.selection_type',
                'modifier_groups.is_required',
                'modifier_groups.min_select',
                'modifier_groups.max_select',
            ])
            ->get();

        if ($links->isEmpty()) {
            return [];
        }

        $groupIds = $links->pluck('group_id')->unique()->values()->all();

        $optionsByGroup = DB::table('modifier_options')
            ->where('merchant_id', $merchantId)
            ->whereIn('modifier_group_id', $groupIds)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'modifier_group_id',
                'name',
                'price_adjustment',
                'is_default',
            ])
            ->groupBy('modifier_group_id');

        $result = [];
        foreach ($links as $link) {
            $groupId = (int) $link->group_id;
            $rawOptions = $optionsByGroup->get($groupId, collect());
            if ($rawOptions->isEmpty()) {
                continue;
            }

            $result[(int) $link->product_id][] = [
                'id' => $groupId,
                'name' => (string) $link->name,
                'selection_type' => (string) $link->selection_type,
                'is_required' => (bool) $link->is_required,
                'min_select' => (int) $link->min_select,
                'max_select' => $link->max_select !== null ? (int) $link->max_select : null,
                'options' => $rawOptions->map(fn ($opt) => [
                    'id' => (int) $opt->id,
                    'name' => (string) $opt->name,
                    'price_adjustment' => (float) $opt->price_adjustment,
                    'is_default' => (bool) $opt->is_default,
                ])->values()->all(),
            ];
        }

        return $result;
    }
}
