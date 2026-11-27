<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\ExtPurchaseRequest\Enums\PurchaseRequestSource;
use Modules\ExtPurchaseRequest\Enums\PurchaseRequestStatus;
use Modules\ExtPurchaseRequest\Models\PurchaseRequest;
use Modules\ExtPurchaseRequest\Models\PurchaseRequestItem;
use Modules\Purchase\Exceptions\PurchaseRuleException;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseDocumentNumberService;
use Modules\Purchase\Services\PurchaseLineResolver;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Services\PurchaseSettingsResolver;
use Modules\Purchase\Support\Numeric;
use Modules\Purchase\Support\Quantity;
use Modules\Purchase\Support\SeparationOfDuties;

/**
 * Purchase Request (PR): permintaan pembelian internal. Tidak menggerakkan stok maupun hutang.
 * Fitur ini OPSIONAL: aktif hanya bila setting merchant purchase.request_enabled = true.
 */
class PurchaseRequestService
{
    private const HEADER_FIELDS = ['branch_id', 'warehouse_id', 'request_date', 'required_date', 'notes'];

    public function __construct(
        private readonly PurchaseLineResolver $lineResolver,
        private readonly PurchaseDocumentNumberService $numbers,
        private readonly PurchaseSettingsResolver $settings,
        private readonly PurchaseOrderService $orders,
        private readonly PurchaseRequestOrderLinker $links,
    ) {}

    // -------------------------------------------------------
    // Query / Listing
    // -------------------------------------------------------

    public function getPaginated(array $filters = [], int $perPage = 15)
    {
        $query = PurchaseRequest::query()
            ->with([
                'warehouse:id,name,code',
                'branch:id,name,code',
            ])
            ->withCount('items');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where('document_number', 'like', $term);
        }

        return $query->orderByDesc('request_date')->orderByDesc('id')->paginate($perPage);
    }

    public function find(int $id): PurchaseRequest
    {
        return PurchaseRequest::query()
            ->with([
                'warehouse:id,name,code',
                'branch:id,name,code',
                'items.productVariant.product:id,name',
                'items.unit:id,name,code',
                'items.suggestedSupplier:id,name,code',
                'items.orderLinks.orderItem.purchaseOrder:id,document_number,status,order_date',
            ])
            ->findOrFail($id);
    }

    public function getOptions(): array
    {
        $merchantId = $this->requireMerchant();
        $warehouses = DB::table('warehouses')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $branches = DB::table('branches')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $suppliers = DB::table('suppliers')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $branchWarehouses = DB::table('branch_warehouses')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->get(['branch_id', 'warehouse_id', 'is_primary']);

        return [
            'warehouses' => $warehouses,
            'branches' => $branches,
            'branch_warehouses' => $branchWarehouses,
            'suppliers' => $suppliers,
            'settings' => [
                'request_enabled' => $this->settings->get()->requestEnabled,
                'separate_approver' => $this->settings->get()->separateApprover,
            ],
        ];
    }

    /**
     * Cari produk/varian untuk item PR:
     * - Menyertakan produk jual (can_be_sold = true, difilter jika cabang punya assortment)
     * - Menyertakan produk non-jual (can_be_sold = false: bahan baku / packaging / operasional)
     * - Menyertakan on_hand_stock jika warehouse_id diberikan
     * - Memfilter exclude_variant_ids agar produk yang sudah dipilih tidak muncul kembali
     */
    public function searchProductOptions(array $filters = []): array
    {
        $merchantId = $this->requireMerchant();

        $search = trim((string) ($filters['search'] ?? ''));
        $warehouseId = isset($filters['warehouse_id']) && $filters['warehouse_id'] !== '' ? (int) $filters['warehouse_id'] : null;
        $branchId = isset($filters['branch_id']) && $filters['branch_id'] !== '' ? (int) $filters['branch_id'] : null;
        $canBeSold = isset($filters['can_be_sold']) ? filter_var($filters['can_be_sold'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $perPage = min(max((int) ($filters['per_page'] ?? 30), 1), 50);

        $excludeVariantIds = collect($filters['exclude_variant_ids'] ?? [])
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->all();

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
            ->whereExists(function ($unitQuery) use ($merchantId) {
                $unitQuery->selectRaw('1')
                    ->from('product_variant_units as pvu')
                    ->join('product_variants as pv_unit', 'pv_unit.id', '=', 'pvu.product_variant_id')
                    ->join('product_unit_conversions as puc', function ($join) {
                        $join->on('puc.product_id', '=', 'pv_unit.product_id')
                            ->on('puc.unit_id', '=', 'pvu.unit_id');
                    })
                    ->join('units as u', 'u.id', '=', 'pvu.unit_id')
                    ->whereColumn('pvu.product_variant_id', 'pv.id')
                    ->where('pvu.merchant_id', $merchantId)
                    ->where('pv_unit.merchant_id', $merchantId)
                    ->where('puc.merchant_id', $merchantId)
                    ->where('pvu.is_active', true)
                    ->where('u.is_active', true)
                    ->whereNull('pvu.deleted_at')
                    ->whereNull('u.deleted_at')
                    ->where(function ($q) use ($merchantId) {
                        $q->whereNull('u.merchant_id')->orWhere('u.merchant_id', $merchantId);
                    });
            });

        if ($excludeVariantIds !== []) {
            $query->whereNotIn('pv.id', $excludeVariantIds);
        }

        // 1. Validasi relasi Gudang & Cabang jika keduanya dipilih
        if ($warehouseId !== null && $branchId !== null) {
            $isLinked = DB::table('branch_warehouses')
                ->where('merchant_id', $merchantId)
                ->where('warehouse_id', $warehouseId)
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->exists();

            if (! $isLinked) {
                return [];
            }
        }

        // 2. Tentukan target cabang untuk penyaringan produk aktif
        $targetBranchIds = [];
        if ($branchId !== null) {
            $targetBranchIds = [(int) $branchId];
        } elseif ($warehouseId !== null) {
            $targetBranchIds = DB::table('branch_warehouses')
                ->where('merchant_id', $merchantId)
                ->where('warehouse_id', $warehouseId)
                ->where('is_active', true)
                ->pluck('branch_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // 3. Filter Cabang (Assortment vs Non-Jual):
        if ($targetBranchIds !== []) {
            $query->where(function ($sub) use ($merchantId, $targetBranchIds) {
                // Selalu sertakan produk non-jual (bahan baku/supplies operasional)
                $sub->where('p.can_be_sold', false);

                // Untuk produk yang dijual, HANYA yang terdaftar di branch_active_products untuk cabang target
                $sub->orWhere(function ($q) use ($merchantId, $targetBranchIds) {
                    $q->where('p.can_be_sold', true)
                        ->whereExists(function ($bap) use ($merchantId, $targetBranchIds) {
                            $bap->selectRaw('1')
                                ->from('branch_active_products as bap')
                                ->whereColumn('bap.product_variant_id', 'pv.id')
                                ->whereIn('bap.branch_id', $targetBranchIds)
                                ->where('bap.merchant_id', $merchantId);
                        });
                });
            });
        }

        // Filter manual can_be_sold jika secara spesifik dikirim
        if ($canBeSold !== null) {
            $query->where('p.can_be_sold', $canBeSold);
        }

        // Filter pencarian (case-insensitive untuk PostgreSQL & SQLite)
        if ($search !== '') {
            $lowerSearch = mb_strtolower($search);
            $like = "%{$lowerSearch}%";
            $query->where(function ($builder) use ($like) {
                $builder->whereRaw('LOWER(p.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(pv.name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(pv.sku) LIKE ?', [$like]);
            });
        }

        $variants = $query
            ->select([
                'pv.id as product_variant_id',
                'p.id as product_id',
                'p.name as product_name',
                'pv.name as variant_name',
                'pv.sku',
                'p.can_be_sold',
            ])
            ->orderBy('p.name')
            ->orderBy('pv.sku')
            ->limit($perPage)
            ->get();

        if ($variants->isEmpty()) {
            return [];
        }

        $variantIds = $variants->pluck('product_variant_id')->all();
        $productIds = $variants->pluck('product_id')->unique()->all();

        // 1. Ambil konversi satuan
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

        // 2. Ambil saldo stok jika warehouse_id diberikan
        $stocksByVariant = $warehouseId
            ? DB::table('warehouse_stocks')
                ->where('merchant_id', $merchantId)
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_variant_id', $variantIds)
                ->pluck('quantity', 'product_variant_id')
            : collect();

        // 3. Ambil supplier utama (jika ada relasi di product_supplier)
        $suppliersByProduct = DB::table('product_supplier')
            ->where('merchant_id', $merchantId)
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        return $variants->map(function ($variant) use ($unitsByVariant, $stocksByVariant, $suppliersByProduct, $warehouseId) {
            $units = ($unitsByVariant->get($variant->product_variant_id) ?? collect())->map(fn ($u) => [
                'unit_id' => (int) $u->unit_id,
                'unit_name' => $u->unit_name,
                'unit_code' => $u->unit_code,
                'conversion_rate' => (float) $u->conversion_rate,
                'is_base' => (bool) $u->is_base,
                'unit_cost' => $u->unit_cost !== null ? (float) $u->unit_cost : 0.0,
            ])->values()->all();

            $onHandStock = (float) ($stocksByVariant->get($variant->product_variant_id) ?? 0.0);
            $typeLabel = $variant->can_be_sold ? '[Jual]' : '[Non-Jual]';
            $stockLabel = $warehouseId ? ' (Stok: ' . rtrim(rtrim(number_format($onHandStock, 4, '.', ''), '0'), '.') . ')' : '';
            $variantSuffix = $variant->variant_name ? ' / ' . $variant->variant_name : '';
            $label = "{$variant->product_name}{$variantSuffix} · SKU {$variant->sku} {$typeLabel}{$stockLabel}";

            $suggestedSupplierId = $suppliersByProduct->get($variant->product_id)?->first()?->supplier_id;

            return [
                'selection' => (string) $variant->product_variant_id,
                'product_variant_id' => (int) $variant->product_variant_id,
                'product_id' => (int) $variant->product_id,
                'product_name' => $variant->product_name,
                'variant_name' => $variant->variant_name,
                'sku' => $variant->sku,
                'can_be_sold' => (bool) $variant->can_be_sold,
                'on_hand_stock' => $onHandStock,
                'suggested_supplier_id' => $suggestedSupplierId ? (int) $suggestedSupplierId : null,
                'label' => $label,
                'units' => $units,
            ];
        })->values()->all();
    }

    public function getApprovedItems(array $filters = []): Collection
    {
        return PurchaseRequestItem::query()
            ->whereHas('purchaseRequest', function ($q) use ($filters) {
                $q->whereIn('status', [PurchaseRequestStatus::Approved, PurchaseRequestStatus::PartiallyOrdered])
                    ->when($filters['purchase_request_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
                    ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
                    ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id));
            })
            ->when($filters['product_variant_id'] ?? null, fn ($q, $id) => $q->where('product_variant_id', $id))
            ->whereRaw('base_quantity > ordered_base_quantity')
            ->with([
                'purchaseRequest:id,document_number,request_date,required_date,warehouse_id,branch_id',
                'purchaseRequest.warehouse:id,name,code',
                'purchaseRequest.branch:id,name',
                'productVariant:id,product_id,sku,name',
                'productVariant.product:id,name',
                'unit:id,name,code',
                'suggestedSupplier:id,name,code',
            ])
            ->get();
    }

    public function getApprovedItemOptions(string $type, string $search = ''): Collection
    {
        $like = '%'.trim($search).'%';
        $statuses = [PurchaseRequestStatus::Approved, PurchaseRequestStatus::PartiallyOrdered];

        if ($type === 'purchase_request') {
            return PurchaseRequest::query()
                ->whereIn('status', $statuses)
                ->whereHas('items', fn ($q) => $q->whereRaw('base_quantity > ordered_base_quantity'))
                ->where('document_number', 'like', $like)
                ->orderBy('document_number')
                ->limit(30)
                ->get(['id', 'document_number as label']);
        }

        if ($type === 'warehouse' || $type === 'branch') {
            $relation = $type === 'warehouse' ? 'warehouse' : 'branch';
            $column = $type === 'warehouse' ? 'warehouse_id' : 'branch_id';

            return PurchaseRequest::query()
                ->whereIn('status', $statuses)
                ->whereNotNull($column)
                ->whereHas('items', fn ($q) => $q->whereRaw('base_quantity > ordered_base_quantity'))
                ->whereHas($relation, fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->with($relation.':id,name,code')
                ->get([$column])
                ->unique($column)
                ->map(fn ($request) => [
                    'id' => $request->{$column},
                    'label' => $request->{$relation}?->name.($request->{$relation}?->code ? ' ('.$request->{$relation}->code.')' : ''),
                ])
                ->values();
        }

        if ($type === 'item') {
            return PurchaseRequestItem::query()
                ->whereRaw('base_quantity > ordered_base_quantity')
                ->whereHas('purchaseRequest', fn ($q) => $q->whereIn('status', $statuses))
                ->whereHas('productVariant', fn ($q) => $q
                    ->where('sku', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', $like)))
                ->with('productVariant.product:id,name')
                ->get(['product_variant_id'])
                ->unique('product_variant_id')
                ->take(30)
                ->map(fn ($item) => [
                    'id' => $item->product_variant_id,
                    'label' => $item->productVariant?->product?->name.($item->productVariant?->name ? ' / '.$item->productVariant->name : '').' · SKU '.$item->productVariant?->sku,
                ])
                ->values();
        }

        return collect();
    }

    // -------------------------------------------------------
    // Siklus dokumen
    // -------------------------------------------------------

    public function create(array $data, array $lines): PurchaseRequest
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($data, $lines) {
            $this->assertWarehouse((int) $data['warehouse_id'], $data['branch_id'] ?? null);
            $items = $this->prepareItems($lines);

            $request = new PurchaseRequest();
            $request->fill(array_merge(
                Arr::only($data, self::HEADER_FIELDS),
                ['source' => $this->source($data), 'document_number' => $this->numbers->generate('purchase_request')],
            ));
            $request->save();
            $request->items()->createMany($items);

            return $request->load('items');
        });
    }

    /** Hanya draft; item diganti seluruhnya. */
    public function update(int|PurchaseRequest $request, array $data, array $lines): PurchaseRequest
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($request, $data, $lines) {
            $request = $this->lock($request);

            if (! $request->status->isEditable()) {
                throw new PurchaseRuleException('Hanya Purchase Request berstatus draft yang dapat diubah.');
            }

            $this->assertWarehouse((int) $data['warehouse_id'], $data['branch_id'] ?? null);
            $items = $this->prepareItems($lines);

            $request->fill(Arr::only($data, self::HEADER_FIELDS))->save();
            $request->items()->delete();
            $request->items()->createMany($items);

            return $request->load('items');
        });
    }

    public function delete(int|PurchaseRequest $request): void
    {
        $this->assertEnabled();

        DB::transaction(function () use ($request) {
            $request = $this->lock($request);

            if (! $request->status->isEditable()) {
                throw new PurchaseRuleException('Hanya draft yang dapat dihapus. PR lain dibatalkan atau ditutup.');
            }

            $request->delete(); // soft delete
        });
    }

    public function submit(int|PurchaseRequest $request): PurchaseRequest
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($request) {
            $request = $this->lock($request);

            if (! $request->status->canSubmit()) {
                throw new PurchaseRuleException('Hanya Purchase Request berstatus draft yang dapat diajukan.');
            }
            if (! $request->items()->exists()) {
                throw new PurchaseRuleException('Purchase Request tanpa item tidak dapat diajukan.');
            }

            $request->forceFill([
                'status' => PurchaseRequestStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by' => auth('merchant')->id(),
            ])->save();

            return $request;
        });
    }

    public function approve(int|PurchaseRequest $request): PurchaseRequest
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($request) {
            $request = $this->lock($request);

            if (! $request->status->canDecide()) {
                throw new PurchaseRuleException('Hanya Purchase Request yang sudah diajukan yang dapat disetujui.');
            }

            // Pemisahan tugas: pembuat tidak boleh menyetujui PR miliknya sendiri.
            // SeparationOfDuties::assertApprover(
            //     $this->settings->get()->separateApprover,
            //     $request->created_by,
            //     auth('merchant')->id(),
            //     'PR'
            // );

            $request->forceFill([
                'status' => PurchaseRequestStatus::Approved,
                'approved_at' => now(),
                'approved_by' => auth('merchant')->id(),
            ])->save();

            return $request;
        });
    }

    public function reject(int|PurchaseRequest $request, string $reason): PurchaseRequest
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($request, $reason) {
            $request = $this->lock($request);

            if (! $request->status->canDecide()) {
                throw new PurchaseRuleException('Hanya Purchase Request yang sudah diajukan yang dapat ditolak.');
            }

            $request->forceFill([
                'status' => PurchaseRequestStatus::Rejected,
                'rejected_at' => now(),
                'rejected_by' => auth('merchant')->id(),
                'reject_reason' => $reason,
            ])->save();

            return $request;
        });
    }

    public function cancel(int|PurchaseRequest $request, string $reason): PurchaseRequest
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($request, $reason) {
            $request = $this->lock($request);

            if (! $request->status->canBeCancelled()) {
                throw new PurchaseRuleException('Purchase Request pada status ini tidak dapat dibatalkan.');
            }
            // Sudah ada qty yang dipesan: PR harus DITUTUP (close), bukan dibatalkan.
            if ($request->items()->where('ordered_base_quantity', '>', 0)->exists()) {
                throw new PurchaseRuleException('PR sudah dipesan sebagian. Gunakan Tutup PR, bukan Batalkan.');
            }

            $request->forceFill([
                'status' => PurchaseRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth('merchant')->id(),
                'cancel_reason' => $reason,
            ])->save();

            return $request;
        });
    }

    /** Tutup manual PR yang sisanya tidak akan dipesan. */
    public function close(int|PurchaseRequest $request): PurchaseRequest
    {
        $this->assertEnabled();

        return DB::transaction(function () use ($request) {
            $request = $this->lock($request);

            if (! $request->status->canBeClosed()) {
                throw new PurchaseRuleException('Hanya PR yang disetujui atau dipesan sebagian yang dapat ditutup.');
            }

            $request->forceFill([
                'status' => PurchaseRequestStatus::Closed,
                'closed_at' => now(),
                'closed_by' => auth('merchant')->id(),
            ])->save();

            return $request;
        });
    }

    // -------------------------------------------------------
    // Konversi ke PO
    // -------------------------------------------------------

    /**
     * @return Collection<int,PurchaseOrder>
     */
    public function convertToOrders(array $groups): Collection
    {
        $this->assertEnabled();

        if ($groups === []) {
            throw ValidationException::withMessages(['groups' => 'Pilih minimal satu item untuk dijadikan PO.']);
        }

        return DB::transaction(function () use ($groups) {
            $itemIds = [];
            foreach ($groups as $group) {
                foreach ($group['items'] ?? [] as $entry) {
                    $itemIds[] = (int) $entry['purchase_request_item_id'];
                }
            }
            $itemIds = array_values(array_unique($itemIds));
            if ($itemIds === []) {
                throw ValidationException::withMessages(['groups' => 'Pilih minimal satu item untuk dijadikan PO.']);
            }

            $found = PurchaseRequestItem::query()->whereIn('id', $itemIds)->get(['id', 'purchase_request_id']);
            if ($found->count() !== count($itemIds)) {
                throw new PurchaseRuleException('Ada item Purchase Request yang tidak ditemukan.');
            }
            $requests = PurchaseRequest::query()
                ->whereIn('id', $found->pluck('purchase_request_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get();
            $items = PurchaseRequestItem::query()->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($requests as $request) {
                if (! $request->status->canConvert()) {
                    throw new PurchaseRuleException("PR {$request->document_number} belum disetujui atau sudah tidak dapat dipesan.");
                }
            }

            $planned = [];
            $prepared = [];
            foreach ($groups as $g => $group) {
                $prepared[$g] = $this->prepareGroup($g, $group, $items, $planned);
            }

            foreach ($planned as $itemId => $qty) {
                if (bccomp($qty, (string) $items->get($itemId)->remaining_base_quantity, 4) > 0) {
                    throw new PurchaseRuleException("Qty yang dipesan melebihi sisa permintaan pada item PR #{$itemId}.");
                }
            }

            $orders = collect();
            $allocations = [];

            foreach ($prepared as $p) {
                $order = $this->orders->create($p['header'], $p['lines']);

                $orderItems = $order->items->sortBy('id')->values();
                foreach ($p['allocations'] as $a) {
                    $allocations[] = [
                        'purchase_request_item_id' => $a['purchase_request_item_id'],
                        'purchase_order_item_id' => $orderItems[$a['line_index']]->id,
                        'base_quantity' => $a['base_quantity'],
                    ];
                }

                $orders->push($order);
            }

            $this->links->link($allocations);

            return $orders;
        });
    }

    private function prepareGroup(int|string $g, array $group, Collection $items, array &$planned): array
    {
        $entries = array_values($group['items'] ?? []);
        if ($entries === []) {
            throw ValidationException::withMessages(["groups.{$g}.items" => 'Setiap PO harus memiliki minimal satu item.']);
        }

        $this->assertSupplier((int) $group['supplier_id'], "groups.{$g}.supplier_id");
        $this->assertWarehouse((int) $group['warehouse_id'], $group['branch_id'] ?? null);

        $lines = [];
        $seenItems = [];
        foreach ($entries as $e => $entry) {
            $itemId = (int) $entry['purchase_request_item_id'];
            if (isset($seenItems[$itemId])) {
                throw ValidationException::withMessages(["groups.{$g}.items.{$e}.purchase_request_item_id" => 'Item PR yang sama dipilih dua kali dalam satu PO.']);
            }
            $seenItems[$itemId] = true;

            $prItem = $items->get($itemId);
            $lines[] = array_merge($entry, [
                'product_variant_id' => $prItem->product_variant_id,
                'unit_id' => $entry['unit_id'] ?? $prItem->unit_id,
            ]);
        }

        $resolved = $this->lineResolver->resolve($lines, '0');

        $poLines = [];
        $lineIndexByKey = [];
        $allocations = [];

        foreach ($entries as $e => $entry) {
            $qty = Numeric::string($entry['unit_quantity'] ?? null);
            if (! preg_match('/^\d+(\.\d{1,4})?$/', $qty) || bccomp($qty, '0', 4) <= 0) {
                throw ValidationException::withMessages(["groups.{$g}.items.{$e}.unit_quantity" => 'Jumlah harus angka lebih besar dari 0 (maksimal 4 desimal).']);
            }

            $itemId = (int) $entry['purchase_request_item_id'];
            $base = Quantity::toBase($qty, (string) $resolved[$e]['conversion_rate']);
            $planned[$itemId] = bcadd($planned[$itemId] ?? '0', $base, 4);

            $variantId = (int) $resolved[$e]['product_variant_id'];
            $unitId = (int) $resolved[$e]['unit_id'];
            $key = "{$variantId}:{$unitId}";

            $signature = [
                Numeric::string($entry['unit_price'] ?? null),
                (string) ($entry['discount_type'] ?? ''),
                Numeric::string($entry['discount_value'] ?? null),
                (string) ($entry['tax_rate'] ?? ''),
            ];

            if (! isset($lineIndexByKey[$key])) {
                $lineIndexByKey[$key] = count($poLines);
                $poLines[] = [
                    'product_variant_id' => $variantId,
                    'unit_id' => $unitId,
                    'unit_quantity' => $qty,
                    'unit_price' => $entry['unit_price'] ?? null,
                    'discount_type' => $entry['discount_type'] ?? null,
                    'discount_value' => $entry['discount_value'] ?? null,
                    'tax_rate' => $entry['tax_rate'] ?? null,
                    'notes' => $entry['notes'] ?? null,
                    '_signature' => $signature,
                ];
            } else {
                $index = $lineIndexByKey[$key];
                if ($poLines[$index]['_signature'] !== $signature) {
                    throw ValidationException::withMessages(["groups.{$g}.items.{$e}.unit_price" => 'Harga, diskon, atau pajak berbeda untuk produk yang sama dalam satu PO. Samakan atau pisahkan menjadi PO lain.']);
                }
                $poLines[$index]['unit_quantity'] = bcadd($poLines[$index]['unit_quantity'], $qty, 4);
            }

            $allocations[] = [
                'purchase_request_item_id' => $itemId,
                'line_index' => $lineIndexByKey[$key],
                'base_quantity' => $base,
            ];
        }

        $poLines = array_map(static fn (array $line): array => Arr::except($line, ['_signature']), $poLines);

        return [
            'header' => Arr::only($group, [
                'branch_id', 'supplier_id', 'warehouse_id', 'order_date', 'expected_date', 'notes',
                'is_tax_inclusive', 'discount_type', 'discount_value',
            ]),
            'lines' => $poLines,
            'allocations' => $allocations,
        ];
    }

    private function prepareItems(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Purchase Request harus memiliki minimal satu item.']);
        }

        $lines = array_values($lines);
        $resolved = $this->lineResolver->resolve($lines, '0');
        $this->assertSuppliers($lines);

        $rows = [];
        $seen = [];
        foreach ($resolved as $i => $line) {
            $qty = Numeric::string($line['unit_quantity'] ?? null);
            if (! preg_match('/^\d+(\.\d{1,4})?$/', $qty) || bccomp($qty, '0', 4) <= 0) {
                throw ValidationException::withMessages(["items.{$i}.unit_quantity" => 'Jumlah harus angka lebih besar dari 0 (maksimal 4 desimal).']);
            }

            $variantId = (int) $line['product_variant_id'];
            if (isset($seen[$variantId])) {
                throw ValidationException::withMessages(["items.{$i}.product_variant_id" => 'Produk tersebut sudah ada pada baris lain.']);
            }
            $seen[$variantId] = true;

            $rows[] = [
                'product_variant_id' => (int) $line['product_variant_id'],
                'unit_id' => (int) $line['unit_id'],
                'unit_quantity' => $qty,
                'conversion_rate' => Numeric::string($line['conversion_rate']),
                'base_quantity' => Quantity::toBase($qty, Numeric::string($line['conversion_rate'])),
                'on_hand_base_quantity' => $this->optionalAmount($line['on_hand_base_quantity'] ?? null, "items.{$i}.on_hand_base_quantity"),
                'suggested_supplier_id' => isset($line['suggested_supplier_id']) ? (int) $line['suggested_supplier_id'] : null,
                'estimated_unit_price' => $this->optionalAmount($line['estimated_unit_price'] ?? null, "items.{$i}.estimated_unit_price"),
                'notes' => $line['notes'] ?? null,
            ];
        }

        return $rows;
    }

    private function optionalAmount(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = Numeric::string($value);
        if (! preg_match('/^\d+(\.\d{1,4})?$/', $amount)) {
            throw ValidationException::withMessages([$field => 'Nilai harus angka tidak negatif (maksimal 4 desimal).']);
        }

        return $amount;
    }

    private function source(array $data): PurchaseRequestSource
    {
        return PurchaseRequestSource::tryFrom((string) ($data['source'] ?? 'manual'))
            ?? throw ValidationException::withMessages(['source' => 'Sumber Purchase Request tidak valid.']);
    }

    private function assertWarehouse(int $warehouseId, mixed $branchId): void
    {
        $merchantId = $this->requireMerchant();

        $exists = DB::table('warehouses')
            ->where('merchant_id', $merchantId)
            ->where('id', $warehouseId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['warehouse_id' => 'Gudang tidak aktif atau bukan bagian dari merchant ini.']);
        }

        if ($branchId !== null && $branchId !== '') {
            $linked = DB::table('branch_warehouses')
                ->where('merchant_id', $merchantId)
                ->where('warehouse_id', $warehouseId)
                ->where('branch_id', (int) $branchId)
                ->where('is_active', true)
                ->exists();

            if (! $linked) {
                throw ValidationException::withMessages(['branch_id' => 'Cabang tidak terhubung dengan gudang yang dipilih.']);
            }
        }
    }

    private function assertSupplier(int $supplierId, string $field): void
    {
        $exists = DB::table('suppliers')
            ->where('merchant_id', $this->requireMerchant())
            ->where('id', $supplierId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => 'Supplier tidak ditemukan.']);
        }
    }

    private function assertSuppliers(array $lines): void
    {
        $ids = collect($lines)->pluck('suggested_supplier_id')->filter()->map(fn ($id) => (int) $id)->unique();
        if ($ids->isEmpty()) {
            return;
        }

        $valid = DB::table('suppliers')
            ->where('merchant_id', $this->requireMerchant())
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        foreach ($lines as $i => $line) {
            $id = (int) ($line['suggested_supplier_id'] ?? 0);
            if ($id > 0 && ! $valid->contains($id)) {
                throw ValidationException::withMessages(["items.{$i}.suggested_supplier_id" => 'Supplier tidak ditemukan.']);
            }
        }
    }

    private function assertEnabled(): void
    {
        if (! $this->settings->get()->requestEnabled) {
            throw new PurchaseRuleException('Fitur Purchase Request belum diaktifkan untuk merchant ini.');
        }
    }

    private function lock(int|PurchaseRequest $request): PurchaseRequest
    {
        $id = $request instanceof PurchaseRequest ? $request->getKey() : $request;

        return PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
    }

    private function requireMerchant(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        return (int) MerchantContext::id();
    }
}
