<?php

namespace Modules\Inventory\Services;

use Modules\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\Quantity;
use Modules\Core\Support\ServerSideTable;
use Modules\Inventory\Enums\StockRequestStatus;
use Modules\Inventory\Models\StockRequest;
use Modules\Inventory\Models\StockRequestItem;
use Modules\Inventory\Models\StockTransfer;
use Spatie\QueryBuilder\AllowedFilter;

class StockRequestService
{
    public function __construct(
        protected DocumentNumberService $documentNumberService,
        protected StockTransferService $stockTransferService,
    ) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $merchantId = $this->requireMerchant();
        $config = new DataTableConfig(
            allowedFields: [
                'id', 'document_number', 'branch_id', 'branch_name',
                'destination_warehouse_id', 'destination_warehouse_name',
                'source_warehouse_id', 'source_warehouse_name',
                'request_date', 'expected_date', 'status',
                'items_count', 'notes', 'created_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('status', 'stock_requests.status'),
                AllowedFilter::exact('branch_id', 'stock_requests.branch_id'),
                AllowedFilter::exact('destination_warehouse_id', 'stock_requests.destination_warehouse_id'),
                AllowedFilter::exact('source_warehouse_id', 'stock_requests.source_warehouse_id'),
            ],
            allowedSorts: ['id', 'document_number', 'request_date', 'expected_date', 'status', 'created_at'],
            searchableFields: ['stock_requests.document_number', 'stock_requests.notes', 'b.name', 'dw.name', 'sw.name'],
            defaultSortField: 'stock_requests.id',
            defaultSortDirection: 'desc',
        );

        $query = StockRequest::query()
            ->where('stock_requests.merchant_id', $merchantId)
            ->join('branches as b', 'b.id', '=', 'stock_requests.branch_id')
            ->join('warehouses as dw', 'dw.id', '=', 'stock_requests.destination_warehouse_id')
            ->leftJoin('warehouses as sw', 'sw.id', '=', 'stock_requests.source_warehouse_id')
            ->withCount('items')
            ->select([
                'stock_requests.id',
                'stock_requests.document_number',
                'stock_requests.branch_id',
                'b.name as branch_name',
                'stock_requests.destination_warehouse_id',
                'dw.name as destination_warehouse_name',
                'stock_requests.source_warehouse_id',
                'sw.name as source_warehouse_name',
                'stock_requests.request_date',
                'stock_requests.expected_date',
                'stock_requests.status',
                'stock_requests.notes',
                'stock_requests.created_at',
            ]);

        return ServerSideTable::paginate($query, $config, $request);
    }

    public function findForCurrentMerchant(int $id): StockRequest
    {
        return StockRequest::query()
            ->where('merchant_id', $this->requireMerchant())
            ->with([
                'branch:id,name,code',
                'destinationWarehouse:id,name,code',
                'sourceWarehouse:id,name,code',
                'items.productVariant.product',
                'items.unit',
                'creator:id,name',
                'submitter:id,name',
                'approver:id,name',
                'rejecter:id,name',
                'canceller:id,name',
                'transfers:id,document_number,status,transfer_date',
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

        $branchWarehouses = DB::table('branch_warehouses')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->get(['branch_id', 'warehouse_id', 'is_primary']);

        return [
            'warehouses' => $warehouses,
            'branches' => $branches,
            'branch_warehouses' => $branchWarehouses,
        ];
    }

    public function searchProductOptions(array $filters = []): array
    {
        $merchantId = $this->requireMerchant();

        $search = trim((string) ($filters['search'] ?? ''));
        $destinationWarehouseId = isset($filters['destination_warehouse_id']) && $filters['destination_warehouse_id'] !== ''
            ? (int) $filters['destination_warehouse_id'] : null;
        $sourceWarehouseId = isset($filters['source_warehouse_id']) && $filters['source_warehouse_id'] !== ''
            ? (int) $filters['source_warehouse_id'] : null;
        $branchId = isset($filters['branch_id']) && $filters['branch_id'] !== ''
            ? (int) $filters['branch_id'] : null;
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
                    ->where('puc.merchant_id', $merchantId)
                    ->where(function ($builder) use ($merchantId) {
                        $builder->whereNull('u.merchant_id')->orWhere('u.merchant_id', $merchantId);
                    })
                    ->where('u.is_active', true);
            });

        if ($excludeVariantIds !== []) {
            $query->whereNotIn('pv.id', $excludeVariantIds);
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
                'p.id as product_id',
                'p.name as product_name',
                'pv.name as variant_name',
                'pv.sku',
                'p.can_be_sold',
            ])
            ->orderBy('p.name')
            ->orderBy('pv.name')
            ->limit($perPage)
            ->get();

        if ($variants->isEmpty()) {
            return [];
        }

        $variantIds = $variants->pluck('product_variant_id')->all();

        // 1. Ambil unit konversi
        $unitsByVariant = DB::table('product_variant_units as pvu')
            ->join('product_variants as pv', 'pv.id', '=', 'pvu.product_variant_id')
            ->join('product_unit_conversions as puc', function ($join) {
                $join->on('puc.product_id', '=', 'pv.product_id')
                    ->on('puc.unit_id', '=', 'pvu.unit_id');
            })
            ->join('units as u', 'u.id', '=', 'pvu.unit_id')
            ->where('pvu.merchant_id', $merchantId)
            ->where('puc.merchant_id', $merchantId)
            ->where(function ($builder) use ($merchantId) {
                $builder->whereNull('u.merchant_id')->orWhere('u.merchant_id', $merchantId);
            })
            ->where('u.is_active', true)
            ->whereIn('pvu.product_variant_id', $variantIds)
            ->select([
                'pvu.product_variant_id',
                'pvu.unit_id',
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

        // 2. Ambil stok di gudang peminta (tujuan)
        $destinationStocks = $destinationWarehouseId
            ? DB::table('warehouse_stocks')
                ->where('merchant_id', $merchantId)
                ->where('warehouse_id', $destinationWarehouseId)
                ->whereIn('product_variant_id', $variantIds)
                ->pluck('quantity', 'product_variant_id')
            : collect();

        // 3. Ambil stok di gudang penyedia (asal)
        $sourceStocks = $sourceWarehouseId
            ? DB::table('warehouse_stocks')
                ->where('merchant_id', $merchantId)
                ->where('warehouse_id', $sourceWarehouseId)
                ->whereIn('product_variant_id', $variantIds)
                ->pluck('quantity', 'product_variant_id')
            : collect();

        return $variants->map(function ($variant) use ($unitsByVariant, $destinationStocks, $sourceStocks, $destinationWarehouseId, $sourceWarehouseId) {
            $units = ($unitsByVariant->get($variant->product_variant_id) ?? collect())->map(fn ($u) => [
                'unit_id' => (int) $u->unit_id,
                'unit_name' => $u->unit_name,
                'unit_code' => $u->unit_code,
                'conversion_rate' => (float) $u->conversion_rate,
                'is_base' => (bool) $u->is_base,
                'unit_cost' => $u->unit_cost !== null ? (float) $u->unit_cost : 0.0,
            ])->values()->all();

            $destStock = (float) ($destinationStocks->get($variant->product_variant_id) ?? 0.0);
            $hasSourceStockRecord = $sourceWarehouseId ? $sourceStocks->has($variant->product_variant_id) : true;
            $srcStock = $sourceWarehouseId && $hasSourceStockRecord
                ? (float) $sourceStocks->get($variant->product_variant_id)
                : null;
            $isAssignedToSource = $sourceWarehouseId ? $hasSourceStockRecord : true;
            $isSourceOutOfStock = $sourceWarehouseId ? (! $hasSourceStockRecord || ($srcStock !== null && $srcStock <= 0)) : false;

            $variantSuffix = $variant->variant_name ? ' / ' . $variant->variant_name : '';
            $stockInfo = 'Stok Cabang: ' . rtrim(rtrim(number_format($destStock, 4, '.', ''), '0'), '.');
            if ($sourceWarehouseId) {
                if (! $hasSourceStockRecord) {
                    $stockInfo .= ' | ⚠️ Belum ada di Gudang Penyuplai';
                } elseif ($srcStock <= 0) {
                    $stockInfo .= ' | ⚠️ Stok Pusat Kosong (0)';
                } else {
                    $stockInfo .= ' | Stok Pusat: ' . rtrim(rtrim(number_format($srcStock, 4, '.', ''), '0'), '.');
                }
            }
            $label = "{$variant->product_name}{$variantSuffix} · SKU {$variant->sku} ({$stockInfo})";

            return [
                'selection' => (string) $variant->product_variant_id,
                'product_variant_id' => (int) $variant->product_variant_id,
                'product_id' => (int) $variant->product_id,
                'product_name' => $variant->product_name,
                'variant_name' => $variant->variant_name,
                'sku' => $variant->sku,
                'destination_stock' => $destStock,
                'source_stock' => $srcStock,
                'is_assigned_to_source' => $isAssignedToSource,
                'is_source_out_of_stock' => $isSourceOutOfStock,
                'label' => $label,
                'units' => $units,
            ];
        })->values()->all();
    }

    /**
     * Mengambil rekomendasi produk yang stoknya di bawah batas minimum (safety stock).
     */
    public function getLowStockSuggestions(int $destinationWarehouseId, ?int $branchId = null): array
    {
        $merchantId = $this->requireMerchant();

        // 1. Ambil threshold aktif di gudang ini
        $thresholds = DB::table('warehouse_stock_thresholds as wst')
            ->join('product_variants as pv', 'pv.id', '=', 'wst.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('warehouse_stocks as ws', function ($join) use ($destinationWarehouseId, $merchantId) {
                $join->on('ws.product_variant_id', '=', 'wst.product_variant_id')
                    ->where('ws.warehouse_id', '=', $destinationWarehouseId)
                    ->where('ws.merchant_id', '=', $merchantId);
            })
            ->where('wst.merchant_id', $merchantId)
            ->where('wst.warehouse_id', $destinationWarehouseId)
            ->where('wst.is_active', true)
            ->where('p.is_active', true)
            ->where('pv.is_active', true)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->select([
                'pv.id as product_variant_id',
                'p.id as product_id',
                'p.name as product_name',
                'pv.name as variant_name',
                'pv.sku',
                'wst.min_quantity',
                'wst.max_quantity',
                DB::raw('COALESCE(ws.quantity, 0) as current_stock'),
            ])
            ->whereRaw('COALESCE(ws.quantity, 0) < wst.min_quantity')
            ->get();

        if ($thresholds->isEmpty()) {
            return [];
        }

        $variantIds = $thresholds->pluck('product_variant_id')->all();

        // Ambil base unit untuk masing-masing varian
        $baseUnits = DB::table('product_variant_units as pvu')
            ->join('product_variants as pv', 'pv.id', '=', 'pvu.product_variant_id')
            ->join('product_unit_conversions as puc', function ($join) {
                $join->on('puc.product_id', '=', 'pv.product_id')
                    ->on('puc.unit_id', '=', 'pvu.unit_id');
            })
            ->join('units as u', 'u.id', '=', 'pvu.unit_id')
            ->where('pvu.merchant_id', $merchantId)
            ->where('puc.merchant_id', $merchantId)
            ->where(function ($builder) use ($merchantId) {
                $builder->whereNull('u.merchant_id')->orWhere('u.merchant_id', $merchantId);
            })
            ->where('puc.is_base', true)
            ->whereIn('pvu.product_variant_id', $variantIds)
            ->select([
                'pvu.product_variant_id',
                'pvu.unit_id',
                'u.name as unit_name',
                'u.code as unit_code',
                'puc.conversion_value as conversion_rate',
            ])
            ->get()
            ->keyBy('product_variant_id');

        return $thresholds->map(function ($item) use ($baseUnits) {
            $currentStock = (float) $item->current_stock;
            $minQty = (float) $item->min_quantity;
            $maxQty = (float) $item->max_quantity;
            $suggestedQty = max($maxQty - $currentStock, 1.0);

            $unit = $baseUnits->get($item->product_variant_id);

            return [
                'product_variant_id' => (int) $item->product_variant_id,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'current_stock' => $currentStock,
                'min_quantity' => $minQty,
                'max_quantity' => $maxQty,
                'suggested_quantity' => $suggestedQty,
                'unit_id' => $unit ? (int) $unit->unit_id : null,
                'unit_name' => $unit?->unit_name ?? '',
                'unit_code' => $unit?->unit_code ?? '',
                'conversion_rate' => $unit ? (float) $unit->conversion_rate : 1.0,
            ];
        })->values()->all();
    }

    public function create(array $data, array $lines): StockRequest
    {
        $merchantId = $this->requireMerchant();

        return DB::transaction(function () use ($data, $lines, $merchantId) {
            $this->validateBranchAndWarehouses(
                (int) $data['branch_id'],
                (int) $data['destination_warehouse_id'],
                isset($data['source_warehouse_id']) && $data['source_warehouse_id'] !== '' ? (int) $data['source_warehouse_id'] : null,
                $merchantId
            );

            $items = $this->prepareItems($lines, $merchantId);

            $request = new StockRequest();
            $request->fill([
                'branch_id' => (int) $data['branch_id'],
                'destination_warehouse_id' => (int) $data['destination_warehouse_id'],
                'source_warehouse_id' => isset($data['source_warehouse_id']) && $data['source_warehouse_id'] !== '' ? (int) $data['source_warehouse_id'] : null,
                'request_date' => $data['request_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'document_number' => $this->documentNumberService->nextStockRequestNumber(),
            ]);
            $request->created_by = Auth::id();
            $request->save();

            $request->items()->createMany($items);

            return $this->findForCurrentMerchant($request->id);
        });
    }

    public function update(int|StockRequest $request, array $data, array $lines): StockRequest
    {
        $merchantId = $this->requireMerchant();

        return DB::transaction(function () use ($request, $data, $lines, $merchantId) {
            $req = $this->lock($request);
            $this->assertStatus($req, [StockRequestStatus::Draft], 'Hanya permohonan berstatus draft yang dapat diubah.');

            $this->validateBranchAndWarehouses(
                (int) $data['branch_id'],
                (int) $data['destination_warehouse_id'],
                isset($data['source_warehouse_id']) && $data['source_warehouse_id'] !== '' ? (int) $data['source_warehouse_id'] : null,
                $merchantId
            );

            $items = $this->prepareItems($lines, $merchantId);

            $req->fill([
                'branch_id' => (int) $data['branch_id'],
                'destination_warehouse_id' => (int) $data['destination_warehouse_id'],
                'source_warehouse_id' => isset($data['source_warehouse_id']) && $data['source_warehouse_id'] !== '' ? (int) $data['source_warehouse_id'] : null,
                'request_date' => $data['request_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $req->updated_by = Auth::id();
            $req->save();

            $req->items()->delete();
            $req->items()->createMany($items);

            return $this->findForCurrentMerchant($req->id);
        });
    }

    public function delete(int|StockRequest $request): void
    {
        $this->requireMerchant();

        DB::transaction(function () use ($request) {
            $req = $this->lock($request);
            $this->assertStatus($req, [StockRequestStatus::Draft], 'Hanya permohonan berstatus draft yang dapat dihapus.');
            $req->delete();
        });
    }

    public function submit(int|StockRequest $request): StockRequest
    {
        $this->requireMerchant();

        return DB::transaction(function () use ($request) {
            $req = $this->lock($request);
            $this->assertStatus($req, [StockRequestStatus::Draft], 'Hanya permohonan draft yang dapat diajukan.');

            if ($req->items()->count() === 0) {
                throw ValidationException::withMessages(['items' => 'Permintaan stok minimal harus memiliki 1 item barang.']);
            }

            $req->status = StockRequestStatus::Submitted;
            $req->submitted_at = now();
            $req->submitted_by = Auth::id();
            $req->save();

            return $this->findForCurrentMerchant($req->id);
        });
    }

    public function approve(int|StockRequest $request, ?array $approvedItems = null): StockRequest
    {
        $this->requireMerchant();

        return DB::transaction(function () use ($request, $approvedItems) {
            $req = $this->lock($request, withItems: true);
            $this->assertStatus($req, [StockRequestStatus::Submitted], 'Hanya permohonan yang berstatus diajukan yang dapat disetujui.');

            $approvedMap = collect($approvedItems ?? [])->keyBy('item_id');

            foreach ($req->items as $item) {
                if ($approvedMap->has($item->id)) {
                    $apprQty = $approvedMap->get($item->id)['approved_base_quantity'] ?? null;
                    $item->approved_base_quantity = $apprQty !== null ? $apprQty : $item->base_quantity;
                } else {
                    $item->approved_base_quantity = $item->base_quantity;
                }
                $item->save();
            }

            $req->status = StockRequestStatus::Approved;
            $req->approved_at = now();
            $req->approved_by = Auth::id();
            $req->save();

            return $this->findForCurrentMerchant($req->id);
        });
    }

    public function reject(int|StockRequest $request, string $reason): StockRequest
    {
        $this->requireMerchant();

        return DB::transaction(function () use ($request, $reason) {
            $req = $this->lock($request);
            $this->assertStatus($req, [StockRequestStatus::Submitted], 'Hanya permohonan yang berstatus diajukan yang dapat ditolak.');

            $req->status = StockRequestStatus::Rejected;
            $req->rejected_at = now();
            $req->rejected_by = Auth::id();
            $req->reject_reason = trim($reason);
            $req->save();

            return $this->findForCurrentMerchant($req->id);
        });
    }

    public function cancel(int|StockRequest $request, string $reason): StockRequest
    {
        $this->requireMerchant();

        return DB::transaction(function () use ($request, $reason) {
            $req = $this->lock($request);
            $this->assertStatus($req, [StockRequestStatus::Draft, StockRequestStatus::Submitted], 'Permohonan tidak dapat dibatalkan.');

            $req->status = StockRequestStatus::Cancelled;
            $req->cancelled_at = now();
            $req->cancelled_by = Auth::id();
            $req->cancel_reason = trim($reason);
            $req->save();

            return $this->findForCurrentMerchant($req->id);
        });
    }

    /**
     * Menerbitkan dokumen StockTransfer pengiriman dari StockRequest.
     */
    public function convertToTransfer(int|StockRequest $request, array $data): StockTransfer
    {
        $merchantId = $this->requireMerchant();

        return DB::transaction(function () use ($request, $data, $merchantId) {
            $req = $this->lock($request, withItems: true);
            $this->assertStatus(
                $req,
                [StockRequestStatus::Approved, StockRequestStatus::PartialTransferred],
                'Hanya permohonan yang disetujui atau dikirim sebagian yang dapat diterbitkan dokumen transfer.'
            );

            $sourceWarehouseId = (int) ($data['from_warehouse_id'] ?? $req->source_warehouse_id);
            if (! $sourceWarehouseId) {
                throw ValidationException::withMessages(['from_warehouse_id' => 'Gudang asal / pengirim wajib ditentukan.']);
            }

            if ($sourceWarehouseId === $req->destination_warehouse_id) {
                throw ValidationException::withMessages(['from_warehouse_id' => 'Gudang pengirim tidak boleh sama dengan gudang tujuan.']);
            }

            $itemsPayload = $data['items'] ?? [];
            if (empty($itemsPayload)) {
                throw ValidationException::withMessages(['items' => 'Minimal pilih 1 item barang untuk dikirim.']);
            }

            $itemsMap = $req->items->keyBy('id');
            $transferLines = [];

            foreach ($itemsPayload as $idx => $line) {
                $itemId = (int) ($line['stock_request_item_id'] ?? 0);
                if (! $itemsMap->has($itemId)) {
                    throw ValidationException::withMessages(["items.{$idx}.stock_request_item_id" => 'Item permohonan tidak ditemukan.']);
                }

                $item = $itemsMap->get($itemId);
                $approvedBase = (float) ($item->approved_base_quantity ?? $item->base_quantity);
                $transferredBase = (float) $item->transferred_base_quantity;
                $remainingBase = max($approvedBase - $transferredBase, 0.0);

                if ($remainingBase <= 0.0001) {
                    throw ValidationException::withMessages(["items.{$idx}.unit_quantity" => "Item {$item->productVariant->sku} sudah selesai dikirim seluruhnya."]);
                }

                $sendUnitQty = (string) $line['unit_quantity'];
                $conversionRate = (string) $item->conversion_rate;
                $sendBaseQty = Quantity::toBase($sendUnitQty, $conversionRate);

                if (bccomp($sendBaseQty, (string) $remainingBase, 4) > 0) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.unit_quantity" => "Jumlah kirim melebihi sisa yang disetujui (sisa: {$remainingBase})."
                    ]);
                }

                // Catat penambahan transferred_base_quantity
                $item->transferred_base_quantity = bcadd((string) $transferredBase, $sendBaseQty, 4);
                $item->save();

                $transferLines[] = [
                    'product_variant_id' => $item->product_variant_id,
                    'unit_id' => $item->unit_id,
                    'unit_quantity' => $sendUnitQty,
                    'notes' => $line['notes'] ?? $item->notes,
                ];
            }

            // Buat StockTransfer
            $transferData = [
                'from_warehouse_id' => $sourceWarehouseId,
                'to_warehouse_id' => $req->destination_warehouse_id,
                'transfer_date' => $data['transfer_date'] ?? now()->format('Y-m-d'),
                'notes' => "Transfer permohonan {$req->document_number}. " . ($data['notes'] ?? ''),
            ];

            $transfer = $this->stockTransferService->create($transferData, $transferLines);
            $transfer->stock_request_id = $req->id;
            $transfer->save();

            // Evaluasi status StockRequest
            $req->refresh();
            $allDone = true;
            foreach ($req->items as $item) {
                $appr = (float) ($item->approved_base_quantity ?? $item->base_quantity);
                $trans = (float) $item->transferred_base_quantity;
                if ($trans < $appr) {
                    $allDone = false;
                    break;
                }
            }

            $req->status = $allDone ? StockRequestStatus::Transferred : StockRequestStatus::PartialTransferred;
            $req->save();

            return $transfer;
        });
    }

    private function validateBranchAndWarehouses(int $branchId, int $destinationWarehouseId, ?int $sourceWarehouseId, int $merchantId): void
    {
        $branchValid = DB::table('branches')
            ->where('id', $branchId)
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->exists();

        if (! $branchValid) {
            throw ValidationException::withMessages(['branch_id' => 'Cabang peminta tidak valid atau nonaktif.']);
        }

        $destWarehouseValid = DB::table('warehouses')
            ->where('id', $destinationWarehouseId)
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->exists();

        if (! $destWarehouseValid) {
            throw ValidationException::withMessages(['destination_warehouse_id' => 'Gudang tujuan tidak valid atau nonaktif.']);
        }

        // Cek keterhubungan gudang dengan cabang
        $branchConnected = DB::table('branch_warehouses')
            ->where('branch_id', $branchId)
            ->where('warehouse_id', $destinationWarehouseId)
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->exists();

        if (! $branchConnected) {
            throw ValidationException::withMessages(['destination_warehouse_id' => 'Gudang tujuan tidak terhubung dengan cabang peminta.']);
        }

        if ($sourceWarehouseId !== null) {
            if ($sourceWarehouseId === $destinationWarehouseId) {
                throw ValidationException::withMessages(['source_warehouse_id' => 'Gudang penyedia tidak boleh sama dengan gudang tujuan.']);
            }

            $sourceValid = DB::table('warehouses')
                ->where('id', $sourceWarehouseId)
                ->where('merchant_id', $merchantId)
                ->where('is_active', true)
                ->exists();

            if (! $sourceValid) {
                throw ValidationException::withMessages(['source_warehouse_id' => 'Gudang penyedia tidak valid atau nonaktif.']);
            }
        }
    }

    private function prepareItems(array $lines, int $merchantId): array
    {
        if (empty($lines)) {
            throw ValidationException::withMessages(['items' => 'Permintaan stok minimal harus memiliki 1 item barang.']);
        }

        $seen = [];
        $rows = [];

        foreach ($lines as $i => $line) {
            if (! isset($line['product_variant_id'], $line['unit_id'], $line['unit_quantity'])) {
                throw ValidationException::withMessages(["items.{$i}" => 'Item tidak lengkap.']);
            }

            $variantId = (int) $line['product_variant_id'];
            if (isset($seen[$variantId])) {
                throw ValidationException::withMessages(["items.{$i}.product_variant_id" => 'Produk tersebut sudah ada pada baris lain.']);
            }
            $seen[$variantId] = true;

            $qtyStr = trim((string) $line['unit_quantity']);
            if (! preg_match('/^\d+(\.\d{1,4})?$/', $qtyStr) || (float) $qtyStr <= 0) {
                throw ValidationException::withMessages(["items.{$i}.unit_quantity" => 'Jumlah harus angka lebih besar dari 0 (maksimal 4 desimal).']);
            }

            // Ambil konversi satuan
            $conversion = DB::table('product_unit_conversions as puc')
                ->join('product_variants as pv', 'pv.product_id', '=', 'puc.product_id')
                ->where('pv.id', $variantId)
                ->where('puc.unit_id', (int) $line['unit_id'])
                ->where('puc.merchant_id', $merchantId)
                ->value('puc.conversion_value');

            if ($conversion === null) {
                throw ValidationException::withMessages(["items.{$i}.unit_id" => 'Satuan tidak valid untuk varian produk tersebut.']);
            }

            $baseQty = Quantity::toBase($qtyStr, (string) $conversion);

            $rows[] = [
                'merchant_id' => $merchantId,
                'product_variant_id' => $variantId,
                'unit_id' => (int) $line['unit_id'],
                'unit_quantity' => $qtyStr,
                'conversion_rate' => $conversion,
                'base_quantity' => $baseQty,
                'approved_base_quantity' => $baseQty,
                'transferred_base_quantity' => 0,
                'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
            ];
        }

        return $rows;
    }

    private function lock(int|StockRequest $request, bool $withItems = false): StockRequest
    {
        $id = $request instanceof StockRequest ? $request->id : $request;
        $query = StockRequest::query()->where('merchant_id', $this->requireMerchant());

        if ($withItems) {
            $query->with('items');
        }

        /** @var StockRequest $model */
        $model = $query->lockForUpdate()->findOrFail($id);

        return $model;
    }

    private function assertStatus(StockRequest $request, array $allowedStatuses, string $errorMessage): void
    {
        if (! in_array($request->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages(['status' => $errorMessage]);
        }
    }

    private function requireMerchant(): int
    {
        $id = MerchantContext::id();
        abort_unless($id, 403, 'Merchant context is required.');

        return (int) $id;
    }
}
