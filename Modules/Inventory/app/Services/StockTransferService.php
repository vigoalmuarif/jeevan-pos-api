<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\Quantity;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Inventory\Enums\StockTransferStatus;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Support\TransferQuantities;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Transfer stok antar gudang, dua langkah: kirim lalu terima.
 *
 * Format input (sudah tervalidasi Form Request):
 *   $data  : from_warehouse_id, to_warehouse_id, transfer_date, notes?
 *   $lines : list of [product_variant_id, unit_id, unit_quantity, notes?]
 *
 * Alur uang/stok (semuanya lewat StockService::recordMutation, satu-satunya pintu stok):
 *  - ship()    : mutasi KELUAR di gudang asal. HPP item dibekukan sebesar unit_cost hasil mutasi
 *                (avg_cost asal saat itu).
 *  - receive() : mutasi MASUK di gudang tujuan dengan biaya beku tersebut, sehingga HPP terbawa.
 *                Qty diterima boleh lebih kecil dari yang dikirim; selisih dicatat pada item dan
 *                wajib disertai catatan (barang hilang/rusak di perjalanan, tidak kembali ke asal).
 *  - cancel()  : draft cukup ditandai batal; shipped membalik mutasi keluar (stok kembali ke asal).
 *
 * Semua perubahan berjalan dalam DB::transaction() dengan kunci baris; item selalu diproses
 * berurutan varian agar baris stok dikunci dalam urutan tetap (bebas deadlock).
 */
class StockTransferService
{
    /** Tipe mutasi stok (string, mengikuti konvensi StockService/Inventory). */
    private const MUTATION_OUT = 'transfer_out';
    private const MUTATION_IN = 'transfer_in';
    private const MUTATION_OUT_VOID = 'transfer_out_void';

    /** Alias dokumen pada stock_mutations.reference_type. */
    private const REFERENCE_TYPE = 'stock_transfer';

    public function __construct(
        protected StockService $stockService,
        protected DocumentNumberService $documentNumberService,
    ) {}

    // -------------------------------------------------------
    // Draft
    // -------------------------------------------------------

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $merchantId = $this->requireMerchant();
        $config = new DataTableConfig(
            allowedFields: [
                'id', 'document_number', 'from_warehouse_id', 'from_warehouse_name',
                'to_warehouse_id', 'to_warehouse_name', 'transfer_date', 'status',
                'items_count', 'notes', 'created_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('status', 'stock_transfers.status'),
                AllowedFilter::exact('from_warehouse_id', 'stock_transfers.from_warehouse_id'),
                AllowedFilter::exact('to_warehouse_id', 'stock_transfers.to_warehouse_id'),
            ],
            allowedSorts: ['stock_transfers.document_number', 'stock_transfers.transfer_date', 'stock_transfers.status', 'from_warehouses.name', 'to_warehouses.name'],
            searchableColumns: ['stock_transfers.document_number', 'from_warehouses.name', 'to_warehouses.name', 'stock_transfers.notes'],
            defaultSort: '-stock_transfers.transfer_date',
            withCount: ['items'],
        );

        $query = StockTransfer::query()
            ->join('warehouses as from_warehouses', 'from_warehouses.id', '=', 'stock_transfers.from_warehouse_id')
            ->join('warehouses as to_warehouses', 'to_warehouses.id', '=', 'stock_transfers.to_warehouse_id')
            ->where('stock_transfers.merchant_id', $merchantId)
            ->where('from_warehouses.merchant_id', $merchantId)
            ->where('to_warehouses.merchant_id', $merchantId)
            ->whereNull('from_warehouses.deleted_at')
            ->whereNull('to_warehouses.deleted_at')
            ->select([
                'stock_transfers.id', 'stock_transfers.document_number',
                'stock_transfers.from_warehouse_id', 'from_warehouses.name as from_warehouse_name',
                'stock_transfers.to_warehouse_id', 'to_warehouses.name as to_warehouse_name',
                'stock_transfers.transfer_date', 'stock_transfers.status',
                'stock_transfers.notes', 'stock_transfers.created_at',
            ]);

        return ServerSideTable::paginate($query, $config, $request);
    }

    public function getInTransit(): \Illuminate\Support\Collection
    {
        return StockTransfer::query()
            ->where('merchant_id', $this->requireMerchant())
            ->where('status', StockTransferStatus::Shipped)
            ->with(['fromWarehouse:id,name,code', 'toWarehouse:id,name,code', 'items.productVariant.product', 'items.unit'])
            ->orderBy('transfer_date')
            ->orderBy('id')
            ->get();
    }

    public function findForCurrentMerchant(int $id): StockTransfer
    {
        return StockTransfer::query()
            ->where('merchant_id', $this->requireMerchant())
            ->with(['fromWarehouse:id,name,code', 'toWarehouse:id,name,code', 'items.productVariant.product', 'items.unit'])
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

        return ['warehouses' => $warehouses];
    }

    /**
     * Product yang tersedia di gudang asal untuk combobox transfer stok.
     * Query ini sengaja tidak bergantung pada relasi branch_warehouses karena
     * transfer menggunakan warehouse aktif milik merchant secara langsung.
     */
    public function searchProductOptions(
        int $warehouseId,
        string $search,
        int $perPage = 15,
        ?int $selectedVariantId = null,
        ?int $destinationWarehouseId = null,
    ): array {
        $merchantId = $this->requireMerchant();

        $warehouseExists = DB::table('warehouses')
            ->where('id', $warehouseId)
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $warehouseExists) {
            return [];
        }

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
            ->whereExists(function ($stock) use ($merchantId, $warehouseId) {
                $stock->selectRaw('1')
                    ->from('warehouse_stocks as ws')
                    ->whereColumn('ws.product_variant_id', 'pv.id')
                    ->where('ws.merchant_id', $merchantId)
                    ->where('ws.warehouse_id', $warehouseId);
            });

        if ($selectedVariantId !== null) {
            $query->where('pv.id', $selectedVariantId);
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
        $warehouseIds = array_values(array_unique(array_filter([$warehouseId, $destinationWarehouseId])));
        $stocksByWarehouse = DB::table('warehouse_stocks')
            ->where('merchant_id', $merchantId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_variant_id', $variantIds)
            ->get(['warehouse_id', 'product_variant_id', 'quantity'])
            ->groupBy('warehouse_id');
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

        return $variants->map(function ($variant) use ($unitsByVariant, $stocksByWarehouse, $warehouseId, $destinationWarehouseId) {
            $sourceStock = ($stocksByWarehouse->get($warehouseId) ?? collect())
                ->firstWhere('product_variant_id', $variant->product_variant_id)?->quantity ?? 0;
            $destinationStock = $destinationWarehouseId
                ? (($stocksByWarehouse->get($destinationWarehouseId) ?? collect())
                    ->firstWhere('product_variant_id', $variant->product_variant_id)?->quantity ?? 0)
                : null;

            return [
                'product_variant_id' => (int) $variant->product_variant_id,
                'product_name' => $variant->product_name,
                'variant_name' => $variant->variant_name,
                'sku' => $variant->sku,
                'label' => $variant->product_name
                    . ($variant->variant_name ? ' / ' . $variant->variant_name : '')
                    . ' · ' . $variant->sku,
                'stock_quantity' => (float) $sourceStock,
                'source_stock_quantity' => (float) $sourceStock,
                'destination_stock_quantity' => $destinationStock === null ? null : (float) $destinationStock,
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
        })->filter(fn (array $option) => $option['units'] !== [])->values()->all();
    }

    public function create(array $data, array $lines): StockTransfer
    {
        $merchantId = $this->requireMerchant();

        return DB::transaction(function () use ($data, $lines, $merchantId) {
            $this->assertWarehouses((int) $data['from_warehouse_id'], (int) $data['to_warehouse_id'], $merchantId);
            $items = $this->prepareItems($lines, $merchantId);

            $transfer = new StockTransfer();
            $transfer->fill(array_merge(
                Arr::only($data, ['from_warehouse_id', 'to_warehouse_id', 'transfer_date', 'notes']),
                // Method ini perlu ditambahkan di DocumentNumberService Inventory (pola sama dengan
                // nextStockAdjustmentNumber, mis. prefix TRF).
                ['document_number' => $this->documentNumberService->nextStockTransferNumber()],
            ));
            $transfer->save();
            $transfer->items()->createMany($items);

            return $transfer->load('items');
        });
    }

    /** Hanya draft; item diganti seluruhnya (belum ada mutasi yang merujuknya). */
    public function update(int|StockTransfer $transfer, array $data, array $lines): StockTransfer
    {
        $merchantId = $this->requireMerchant();

        return DB::transaction(function () use ($transfer, $data, $lines, $merchantId) {
            $transfer = $this->lock($transfer);
            $this->assertDraft($transfer);

            $this->assertWarehouses((int) $data['from_warehouse_id'], (int) $data['to_warehouse_id'], $merchantId);
            $items = $this->prepareItems($lines, $merchantId);

            $transfer->fill(Arr::only($data, ['from_warehouse_id', 'to_warehouse_id', 'transfer_date', 'notes']))->save();
            $transfer->items()->delete();
            $transfer->items()->createMany($items);

            return $transfer->load('items');
        });
    }

    public function delete(int|StockTransfer $transfer): void
    {
        $this->requireMerchant();

        DB::transaction(function () use ($transfer) {
            $transfer = $this->lock($transfer);
            $this->assertDraft($transfer);

            $transfer->delete(); // soft delete
        });
    }

    // -------------------------------------------------------
    // Kirim, terima, batal
    // -------------------------------------------------------

    /** Kirim: stok keluar dari gudang asal dan HPP dibekukan. */
    public function ship(int|StockTransfer $transfer): StockTransfer
    {
        $this->requireMerchant();

        return DB::transaction(function () use ($transfer) {
            $transfer = $this->lock($transfer, withItems: true);

            if (! $transfer->status->canShip()) {
                throw ValidationException::withMessages(['status' => 'Hanya transfer berstatus draft yang dapat dikirim.']);
            }
            if ($transfer->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Transfer harus memiliki minimal satu item.']);
            }

            $mutatedAt = $transfer->transfer_date->copy()->startOfDay();

            foreach ($this->ordered($transfer) as $item) {
                $mutation = $this->stockService->recordMutation(
                    warehouseId: (int) $transfer->from_warehouse_id,
                    productVariantId: (int) $item->product_variant_id,
                    baseQuantity: -((float) $item->base_quantity),
                    mutationType: self::MUTATION_OUT,
                    referenceType: self::REFERENCE_TYPE,
                    referenceId: (int) $transfer->id,
                    referenceNumber: $transfer->document_number,
                    notes: $item->notes ?: $transfer->notes,
                    mutatedAt: $mutatedAt,
                );

                // Biaya keluar = avg_cost gudang asal saat ini; dibekukan sebagai biaya masuk di tujuan.
                $item->forceFill(['unit_cost' => number_format((float) $mutation->unit_cost, 4, '.', '')])->save();
            }

            $transfer->forceFill([
                'status' => StockTransferStatus::Shipped,
                'shipped_at' => now(),
                'shipped_by' => auth()->guard('merchant')->id(),
                'updated_by' => auth()->guard('merchant')->id(),
            ])->save();

            return $transfer;
        });
    }

    /**
     * Terima: stok masuk di gudang tujuan.
     *
     * @param array<int,mixed> $received id item transfer => qty diterima (satuan dasar). Item yang
     *                                   tidak disebut dianggap diterima PENUH sebesar yang dikirim.
     * @param string|null      $notes    wajib bila ada qty diterima yang berbeda dari yang dikirim
     */
    public function receive(int|StockTransfer $transfer, array $received = [], ?string $notes = null): StockTransfer
    {
        $this->requireMerchant();

        return DB::transaction(function () use ($transfer, $received, $notes) {
            $transfer = $this->lock($transfer, withItems: true);

            if (! $transfer->status->canReceive()) {
                throw ValidationException::withMessages(['status' => 'Hanya transfer berstatus dikirim yang dapat diterima.']);
            }

            // Id item yang dikirim client harus bagian dari transfer ini.
            $unknown = array_diff(array_map('intval', array_keys($received)), $transfer->items->pluck('id')->all());
            if ($unknown !== []) {
                throw ValidationException::withMessages(['received' => 'Ada item yang bukan bagian dari transfer ini.']);
            }

            // Hitung dan validasi semua qty dulu; belum ada mutasi yang ditulis.
            $plans = [];
            $hasVariance = false;
            foreach ($transfer->items as $item) {
                try {
                    $qty = TransferQuantities::received((string) $item->base_quantity, $received[$item->id] ?? null);
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages(["received.{$item->id}" => $e->getMessage()]);
                }

                $variance = TransferQuantities::variance((string) $item->base_quantity, $qty);
                $hasVariance = $hasVariance || bccomp($variance, '0', 4) > 0;
                $plans[$item->id] = ['received' => $qty, 'variance' => $variance];
            }

            if ($hasVariance && trim((string) $notes) === '') {
                throw ValidationException::withMessages(['receive_notes' => 'Catatan wajib diisi bila qty diterima berbeda dari qty dikirim.']);
            }

            foreach ($this->ordered($transfer) as $item) {
                $plan = $plans[$item->id];

                if (bccomp($plan['received'], '0', 4) > 0) {
                    $this->stockService->recordMutation(
                        warehouseId: (int) $transfer->to_warehouse_id,
                        productVariantId: (int) $item->product_variant_id,
                        baseQuantity: (float) $plan['received'],
                        mutationType: self::MUTATION_IN,
                        // Biaya beku saat kirim: HPP terbawa, tidak berubah akibat perpindahan.
                        incomingUnitCost: (float) $item->unit_cost,
                        referenceType: self::REFERENCE_TYPE,
                        referenceId: (int) $transfer->id,
                        referenceNumber: $transfer->document_number,
                        notes: $item->notes ?: $transfer->notes,
                    );
                }

                $item->forceFill([
                    'received_base_quantity' => $plan['received'],
                    'variance_base_quantity' => $plan['variance'],
                ])->save();
            }

            $transfer->forceFill([
                'status' => StockTransferStatus::Received,
                'received_at' => now(),
                'received_by' => auth()->guard('merchant')->id(),
                'receive_notes' => $notes,
                'updated_by' => auth()->guard('merchant')->id(),
            ])->save();

            return $transfer;
        });
    }

    /**
     * Batal. Draft: cukup ditandai. Shipped: mutasi keluar dibalik (stok kembali ke gudang asal
     * dengan biaya beku saat kirim). Yang sudah diterima tidak dapat dibatalkan.
     */
    public function cancel(int|StockTransfer $transfer, string $reason): StockTransfer
    {
        $this->requireMerchant();

        return DB::transaction(function () use ($transfer, $reason) {
            $transfer = $this->lock($transfer, withItems: true);

            if (! $transfer->status->canBeCancelled()) {
                throw ValidationException::withMessages(['status' => 'Transfer pada status ini tidak dapat dibatalkan.']);
            }

            if ($transfer->status === StockTransferStatus::Shipped) {
                foreach ($this->ordered($transfer) as $item) {
                    $this->stockService->recordMutation(
                        warehouseId: (int) $transfer->from_warehouse_id,
                        productVariantId: (int) $item->product_variant_id,
                        baseQuantity: (float) $item->base_quantity, // pembalik: bertanda positif
                        mutationType: self::MUTATION_OUT_VOID,
                        incomingUnitCost: (float) $item->unit_cost,
                        referenceType: self::REFERENCE_TYPE,
                        referenceId: (int) $transfer->id,
                        referenceNumber: $transfer->document_number,
                        notes: "Batal: {$reason}",
                    );
                }
            }

            $transfer->forceFill([
                'status' => StockTransferStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->guard('merchant')->id(),
                'cancel_reason' => $reason,
                'updated_by' => auth()->guard('merchant')->id(),
            ])->save();

            return $transfer;
        });
    }

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    /**
     * Validasi baris input dan ubah menjadi atribut item. Syarat produk/satuan SAMA dengan
     * Stock Adjustment (milik merchant, aktif, belum dihapus, produk berstok); satu query untuk semua baris.
     *
     * @return list<array<string,mixed>>
     */
    private function prepareItems(array $lines, int $merchantId): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Transfer harus memiliki minimal satu item.']);
        }

        $lines = array_values($lines);
        $variantIds = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['product_variant_id'], $lines)));

        $units = DB::table('product_variant_units as pvu')
            ->join('product_variants as pv', 'pv.id', '=', 'pvu.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('units as u', 'u.id', '=', 'pvu.unit_id')
            ->whereIn('pvu.product_variant_id', $variantIds)
            ->where('pvu.merchant_id', $merchantId)
            ->where('pv.merchant_id', $merchantId)
            ->where('p.merchant_id', $merchantId)
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
            ->get(['pvu.product_variant_id', 'pvu.unit_id', 'pvu.conversion_value'])
            ->keyBy(fn (object $row): string => $row->product_variant_id.':'.$row->unit_id);

        $rows = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $key = (int) $line['product_variant_id'].':'.(int) $line['unit_id'];

            $unit = $units->get($key);
            if ($unit === null || (float) $unit->conversion_value <= 0) {
                throw ValidationException::withMessages([
                    "items.{$i}.unit_id" => 'Satuan tidak tersedia untuk varian produk yang dipilih.',
                ]);
            }
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "items.{$i}.product_variant_id" => 'Produk dengan satuan yang sama sudah ada pada baris lain.',
                ]);
            }
            $seen[$key] = true;

            $quantity = trim((string) ($line['unit_quantity'] ?? ''));
            if (! preg_match('/^\d+(\.\d{1,4})?$/', $quantity) || bccomp($quantity, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    "items.{$i}.unit_quantity" => 'Jumlah harus angka lebih besar dari 0 (maksimal 4 desimal).',
                ]);
            }

            try {
                $base = Quantity::toBase($quantity, (string) $unit->conversion_value);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(["items.{$i}.unit_quantity" => $e->getMessage()]);
            }

            $rows[] = [
                'product_variant_id' => (int) $line['product_variant_id'],
                'unit_id' => (int) $line['unit_id'],
                'unit_quantity' => $quantity,
                'conversion_rate' => (string) $unit->conversion_value,
                'base_quantity' => $base,
                'notes' => $line['notes'] ?? null,
            ];
        }

        return $rows;
    }

    /** Kedua gudang milik merchant, aktif, dan berbeda. */
    private function assertWarehouses(int $fromId, int $toId, int $merchantId): void
    {
        if ($fromId === $toId) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'Gudang tujuan harus berbeda dari gudang asal.']);
        }

        $found = DB::table('warehouses')
            ->where('merchant_id', $merchantId)
            ->whereIn('id', [$fromId, $toId])
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        foreach (['from_warehouse_id' => $fromId, 'to_warehouse_id' => $toId] as $field => $id) {
            if (! $found->contains($id)) {
                throw ValidationException::withMessages([$field => 'Gudang tidak aktif atau bukan bagian dari merchant ini.']);
            }
        }
    }

    /** Item berurutan (varian, id): baris stok selalu dikunci dalam urutan yang sama. */
    private function ordered(StockTransfer $transfer): \Illuminate\Support\Collection
    {
        return $transfer->items->sortBy([['product_variant_id', 'asc'], ['id', 'asc']])->values();
    }

    private function assertDraft(StockTransfer $transfer): void
    {
        if (! $transfer->status->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Dokumen hanya dapat diubah saat berstatus draft.']);
        }
    }

    /** Kunci baris dan baca ulang (status model dari pemanggil bisa sudah basi). */
    private function lock(int|StockTransfer $transfer, bool $withItems = false): StockTransfer
    {
        $id = $transfer instanceof StockTransfer ? $transfer->getKey() : $transfer;

        $query = StockTransfer::query()->where('merchant_id', $this->requireMerchant())->lockForUpdate();
        if ($withItems) {
            $query->with('items');
        }

        return $query->findOrFail($id);
    }

    private function requireMerchant(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        return (int) MerchantContext::id();
    }
}
