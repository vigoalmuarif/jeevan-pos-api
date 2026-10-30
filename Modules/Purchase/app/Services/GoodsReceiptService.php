<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Inventory\Enums\MutationType;
use Modules\Inventory\Services\StockService;
use Modules\Purchase\Enums\DocumentStatus;
use Modules\Purchase\Exceptions\PurchaseRuleException;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\PurchaseOrder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Siklus hidup Goods Receipt (penerimaan barang): create/update/delete draft, post, void.
 * Inilah SATU-SATUNYA tempat pembelian menggerakkan stok.
 *
 * Format input (sudah tervalidasi Form Request):
 *   $data  : branch_id?, purchase_order_id?, supplier_id, warehouse_id, receipt_date,
 *            delivery_note_number?, notes?, is_tax_inclusive?, discount_type?, discount_value?
 *   $lines : list of [product_variant_id, unit_id, unit_quantity, unit_price,
 *            discount_type?, discount_value?, tax_rate?, notes?, purchase_order_item_id?]
 *
 * Aturan:
 *  - Penerimaan dari PO: setiap baris wajib merujuk item PO dengan varian yang sama dan
 *    tidak boleh melebihi SISA PO. Barang di luar PO dicatat pada penerimaan tanpa PO.
 *  - Draft hanya diperiksa terhadap sisa PO untuk umpan balik cepat; pemeriksaan YANG
 *    MENENTUKAN dilakukan saat post, di bawah kunci baris PO.
 */
class GoodsReceiptService
{
    private const HEADER_FIELDS = [
        'branch_id',
        'purchase_order_id',
        'supplier_id',
        'warehouse_id',
        'receipt_date',
        'delivery_note_number',
        'notes',
    ];

    public function __construct(
        private readonly PurchaseDocumentPreparer $preparer,
        private readonly PurchaseOrderService $orders,
        private readonly StockService $stock,
        private readonly PurchaseDocumentNumberService $numbers,
        private readonly PurchaseSettingsResolver $settings,
    ) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $config = new DataTableConfig(
            allowedFilters: [
                AllowedFilter::exact('status'),
                AllowedFilter::exact('supplier_id'),
                AllowedFilter::exact('warehouse_id'),
            ],
            allowedSorts: ['document_number', 'receipt_date', 'grand_total', 'created_at'],
            searchableColumns: ['document_number', 'delivery_note_number'],
            defaultSort: '-receipt_date',
        );

        return ServerSideTable::paginate(
            GoodsReceipt::query()->with(['supplier:id,name', 'warehouse:id,name'])->withCount('items'),
            $config,
            $request
        );
    }

    /** Pilihan aktif untuk form penerimaan, dibatasi ke merchant saat ini. */
    public function getOptions(): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $suppliers = DB::table('suppliers')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $warehouses = DB::table('warehouses')
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $settings = $this->settings->get();

        $orders = DB::table('purchase_orders')
            ->where('merchant_id', $merchantId)
            ->whereIn('status', ['approved', 'partially_received'])
            ->whereNull('deleted_at')
            ->orderByDesc('order_date')
            ->get(['id', 'document_number', 'supplier_id', 'warehouse_id', 'order_date']);

        return [
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
            'orders' => $orders,
            'settings' => [
                'require_po' => $settings->requirePo,
            ],
        ];
    }

    /** Search active, stock-tracked product variants and their active units for the GR form. */
    public function searchProductUnits(string $search, ?int $supplierId = null): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $variantsQuery = DB::table('products as p')
            ->join('product_variants as pv', 'pv.product_id', '=', 'p.id')
            ->where('p.merchant_id', $merchantId)
            ->where('pv.merchant_id', $merchantId)
            ->where('p.type', 'product')
            ->where('p.track_stock', true)
            ->where('p.is_active', true)
            ->where('pv.is_active', true)
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->whereExists(function ($query) use ($merchantId) {
                $query->selectRaw('1')
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
                    ->where(function ($query) use ($merchantId) {
                        $query->whereNull('u.merchant_id')->orWhere('u.merchant_id', $merchantId);
                    });
            });

        if ($supplierId !== null) {
            $variantsQuery->where(function ($query) use ($merchantId, $supplierId) {
                $query->whereNotExists(function ($supplierQuery) use ($merchantId) {
                    $supplierQuery->selectRaw('1')
                        ->from('product_supplier as ps')
                        ->whereColumn('ps.product_id', 'p.id')
                        ->where('ps.merchant_id', $merchantId);
                })->orWhereExists(function ($supplierQuery) use ($merchantId, $supplierId) {
                    $supplierQuery->selectRaw('1')
                        ->from('product_supplier as ps')
                        ->whereColumn('ps.product_id', 'p.id')
                        ->where('ps.merchant_id', $merchantId)
                        ->where('ps.supplier_id', $supplierId);
                });
            });
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $variantsQuery->where(function ($query) use ($like, $merchantId) {
                $query->where('p.name', 'like', $like)
                    ->orWhere('pv.name', 'like', $like)
                    ->orWhere('pv.sku', 'like', $like)
                    ->orWhereExists(function ($unitsQuery) use ($like, $merchantId) {
                        $unitsQuery->selectRaw('1')
                            ->from('product_variant_units as pvu')
                            ->join('units as u', 'u.id', '=', 'pvu.unit_id')
                            ->whereColumn('pvu.product_variant_id', 'pv.id')
                            ->where('pvu.merchant_id', $merchantId)
                            ->where('pvu.is_active', true)
                            ->whereNull('pvu.deleted_at')
                            ->whereNull('u.deleted_at')
                            ->where(function ($unitsQuery) use ($like) {
                                $unitsQuery->where('u.name', 'like', $like)
                                    ->orWhere('u.code', 'like', $like);
                            });
                    });
            });
        }

        $variants = $variantsQuery
            ->orderBy('p.name')
            ->orderBy('pv.sku')
            ->limit(30)
            ->get([
                'p.id as product_id',
                'p.name as product_name',
                'pv.id as product_variant_id',
                'pv.name as variant_name',
                'pv.sku',
                'p.tax_rate',
            ]);

        if ($variants->isEmpty()) {
            return [];
        }

        $units = DB::table('product_variant_units as pvu')
            ->join('product_variants as pv_unit', 'pv_unit.id', '=', 'pvu.product_variant_id')
            ->join('product_unit_conversions as puc', function ($join) {
                $join->on('puc.product_id', '=', 'pv_unit.product_id')
                    ->on('puc.unit_id', '=', 'pvu.unit_id');
            })
            ->join('units as u', 'u.id', '=', 'pvu.unit_id')
            ->whereIn('pvu.product_variant_id', $variants->pluck('product_variant_id'))
            ->where('pvu.merchant_id', $merchantId)
            ->where('pv_unit.merchant_id', $merchantId)
            ->where('puc.merchant_id', $merchantId)
            ->where('pvu.is_active', true)
            ->where('u.is_active', true)
            ->whereNull('pvu.deleted_at')
            ->whereNull('u.deleted_at')
            ->where(function ($query) use ($merchantId) {
                $query->whereNull('u.merchant_id')->orWhere('u.merchant_id', $merchantId);
            })
            ->orderByDesc('puc.is_base')
            ->orderBy('u.name')
            ->get([
                'pvu.product_variant_id',
                'pvu.unit_id',
                'u.name as unit_name',
                'u.code as unit_code',
                'puc.conversion_value as conversion_rate',
                'puc.is_base',
                'pvu.cost as unit_cost',
            ])
            ->groupBy('product_variant_id');

        return $variants
            ->map(function ($variant) use ($units) {
                return [
                    'product_id' => $variant->product_id,
                    'product_name' => $variant->product_name,
                    'product_variant_id' => $variant->product_variant_id,
                    'variant_name' => $variant->variant_name,
                    'sku' => $variant->sku,
                    'tax_rate' => $variant->tax_rate,
                    'units' => $units->get($variant->product_variant_id, collect())
                        ->map(fn($unit) => (array) $unit)
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }

    public function find(int $id): GoodsReceipt
    {
        return GoodsReceipt::query()
            ->with([
                'supplier:id,name,code',
                'warehouse:id,name,code',
                'purchaseOrder:id,document_number,order_date',
                'items.productVariant.product:id,name',
                'items.unit:id,name,code',
            ])
            ->findOrFail($id);
    }

    public function create(array $data, array $lines): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $lines) {
            if ($this->settings->get()->requirePo && empty($data['purchase_order_id'])) {
                throw new PurchaseRuleException('Pengaturan mewajibkan penerimaan barang dibuat dari Purchase Order.');
            }

            $order = $this->loadOrderForReceipt($data['purchase_order_id'] ?? null, (int) $data['supplier_id']);
            $prepared = $this->preparer->prepare($data, $lines, forReceipt: true);

            if ($order !== null) {
                $this->assertNoDraftForOrder($order);
                $this->assertWithinOrder($order, $prepared->items, lock: false);
            }

            $receipt = new GoodsReceipt();
            $receipt->fill(array_merge(
                Arr::only($data, self::HEADER_FIELDS),
                $prepared->header,
                ['document_number' => $this->numbers->generate('goods_receipt')],
            ));
            $receipt->save();
            $receipt->items()->createMany($prepared->items);

            return $receipt->load('items');
        });
    }

    /** Hanya draft; item diganti seluruhnya karena belum ada stok yang bergerak. */
    public function update(GoodsReceipt $receipt, array $data, array $lines): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $data, $lines) {
            $receipt = $this->lock($receipt);

            if (! $receipt->status->isEditable()) {
                throw new PurchaseRuleException('Hanya penerimaan berstatus draft yang dapat diubah.');
            }

            if ($this->settings->get()->requirePo && empty($data['purchase_order_id'])) {
                throw new PurchaseRuleException('Pengaturan mewajibkan penerimaan barang dibuat dari Purchase Order.');
            }

            $order = $this->loadOrderForReceipt($data['purchase_order_id'] ?? null, (int) $data['supplier_id']);
            $prepared = $this->preparer->prepare($data, $lines, forReceipt: true);

            if ($order !== null) {
                $this->assertNoDraftForOrder($order, $receipt->getKey());
                $this->assertWithinOrder($order, $prepared->items, lock: false);
            }

            $receipt->fill(array_merge(Arr::only($data, self::HEADER_FIELDS), $prepared->header))->save();
            $receipt->items()->delete();
            $receipt->items()->createMany($prepared->items);

            return $receipt->load('items');
        });
    }

    public function delete(GoodsReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt) {
            $receipt = $this->lock($receipt);

            if (! $receipt->status->isEditable()) {
                throw new PurchaseRuleException('Hanya draft yang dapat dihapus. Penerimaan yang sudah diposting dibatalkan (void).');
            }

            $receipt->delete(); // soft delete
        });
    }

    /**
     * Posting: stok bertambah, HPP (moving average) diperbarui, qty diterima pada PO bertambah.
     * Seluruhnya satu transaksi: bila satu item gagal, tidak ada stok yang setengah berubah.
     */
    public function post(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt) {
            // Baca ulang dengan kunci: dua klik "Posting" bersamaan tidak boleh sama-sama lolos.
            $receipt = $this->lock($receipt, withItems: true);

            if (! $receipt->status->canPost()) {
                throw new PurchaseRuleException('Hanya penerimaan berstatus draft yang dapat diposting.');
            }
            if ($receipt->items->isEmpty()) {
                throw new PurchaseRuleException('Penerimaan tanpa item tidak dapat diposting.');
            }

            // Pemeriksaan sisa PO yang MENENTUKAN: baris PO dikunci, sehingga dua penerimaan
            // yang diposting bersamaan tidak bisa sama-sama memakai sisa yang sama.
            $order = null;
            $receivedByOrderItem = [];
            if ($receipt->purchase_order_id !== null) {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($receipt->purchase_order_id);
                if (! $order->status->canReceive()) {
                    throw new PurchaseRuleException('PO sudah tidak dapat menerima barang (status: ' . $order->status->label() . ').');
                }
                $receivedByOrderItem = $this->assertWithinOrder($order, $receipt->items, lock: true);
            }

            // Item diurutkan tetap sebelum memutasi stok: baris warehouse_stocks dikunci
            // berurutan, sehingga dua posting bersamaan tidak saling menunggu (deadlock).
            $items = $receipt->items->sortBy([['product_variant_id', 'asc'], ['id', 'asc']]);

            foreach ($items as $item) {
                // unit_cost (per SATUAN DASAR) sudah memuat diskon, alokasi diskon header,
                // dan pajak bila tax_in_cost; inilah yang memengaruhi moving average.
                $this->stock->recordMutation(
                    warehouseId: $receipt->warehouse_id,
                    productVariantId: $item->product_variant_id,
                    baseQuantity: (float) $item->base_quantity,
                    mutationType: MutationType::PurchaseReceipt->value,
                    incomingUnitCost: (float) $item->unit_cost,
                    referenceType: 'goods_receipt',
                    referenceId: $receipt->id,
                    referenceNumber: $receipt->document_number,
                    notes: $receipt->notes,
                );
            }

            if ($order !== null) {
                $this->orders->applyReceipt($order, $receivedByOrderItem);
            }

            $receipt->forceFill([
                'status' => DocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => auth('merchant')->id(),
            ])->save();

            return $receipt;
        });
    }

    /**
     * Void: stok dikembalikan lewat mutasi pembalik (ledger tidak pernah diedit), dan qty
     * diterima pada PO dikurangi. Ditolak bila sudah difakturkan, atau bila pengembalian
     * membuat stok negatif sementara merchant tidak mengizinkannya (diputuskan StockService).
     */
    public function void(GoodsReceipt $receipt, string $reason): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $reason) {
            $receipt = $this->lock($receipt, withItems: true);

            if (! $receipt->status->canVoid()) {
                throw new PurchaseRuleException('Hanya penerimaan yang sudah diposting yang dapat di-void.');
            }
            // Faktur menyalin nilai penerimaan ini; faktur harus di-void lebih dulu agar
            // hutang dan stok tidak saling bertentangan.
            if ($receipt->isInvoiced()) {
                throw new PurchaseRuleException('Penerimaan sudah difakturkan. Batalkan fakturnya terlebih dahulu.');
            }

            $items = $receipt->items->sortBy([['product_variant_id', 'asc'], ['id', 'asc']]);

            foreach ($items as $item) {
                $this->stock->recordMutation(
                    warehouseId: $receipt->warehouse_id,
                    productVariantId: $item->product_variant_id,
                    baseQuantity: -(float) $item->base_quantity,
                    mutationType: MutationType::PurchaseReceiptVoid->value,
                    referenceType: 'goods_receipt',
                    referenceId: $receipt->id,
                    referenceNumber: $receipt->document_number,
                    notes: "Void {$receipt->document_number}: {$reason}",
                    reversedUnitCost: (float) $item->unit_cost,
                );
            }

            if ($receipt->purchase_order_id !== null) {
                $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($receipt->purchase_order_id);
                $this->orders->revertReceipt($order, $this->sumByOrderItem($receipt->items));
            }

            $receipt->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => auth('merchant')->id(),
                'void_reason' => $reason,
            ])->save();

            return $receipt;
        });
    }

    /** PO harus terbuka dan milik supplier yang sama dengan penerimaan. */
    private function loadOrderForReceipt(mixed $orderId, int $supplierId): ?PurchaseOrder
    {
        if ($orderId === null || $orderId === '') {
            return null;
        }

        // MerchantScope: PO milik merchant lain tidak akan ditemukan.
        // Kunci PO agar pembuatan dua draft untuk PO yang sama berjalan serial.
        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail((int) $orderId);

        if (! $order->status->canReceive()) {
            throw new PurchaseRuleException('PO ini belum disetujui atau sudah tidak dapat menerima barang.');
        }
        if ((int) $order->supplier_id !== $supplierId) {
            throw new PurchaseRuleException('Supplier penerimaan harus sama dengan supplier pada PO.');
        }

        return $order;
    }

    /**
     * Satu PO hanya boleh memiliki satu draft Goods Receipt aktif.
     * Penerimaan posted tetap boleh lebih dari satu untuk mendukung partial receipt.
     */
    private function assertNoDraftForOrder(PurchaseOrder $order, ?int $ignoreReceiptId = null): void
    {
        $query = GoodsReceipt::query()
            ->where('purchase_order_id', $order->getKey())
            ->where('status', DocumentStatus::Draft->value);

        if ($ignoreReceiptId !== null) {
            $query->whereKeyNot($ignoreReceiptId);
        }

        if ($query->exists()) {
            throw new PurchaseRuleException(
                "PO {$order->document_number} sudah memiliki draft penerimaan barang. Selesaikan, ubah, atau hapus draft tersebut terlebih dahulu."
            );
        }
    }

    /**
     * Pastikan setiap baris merujuk item PO yang benar dan total per item PO tidak melebihi sisa.
     *
     * @param  iterable<array<string,mixed>|\Modules\Purchase\Models\GoodsReceiptItem> $items
     * @return array<int,string> id item PO => total qty satuan dasar yang diminta
     */
    private function assertWithinOrder(PurchaseOrder $order, iterable $items, bool $lock): array
    {
        $query = $order->items();
        if ($lock) {
            $query->lockForUpdate();
        }
        $orderItems = $query->get()->keyBy('id');

        $requested = [];
        foreach ($items as $item) {
            $orderItemId = data_get($item, 'purchase_order_item_id');
            if ($orderItemId === null) {
                throw new PurchaseRuleException('Setiap baris penerimaan dari PO harus merujuk item PO. Barang di luar PO dicatat pada penerimaan tanpa PO.');
            }

            $orderItem = $orderItems->get((int) $orderItemId)
                ?? throw new PurchaseRuleException("Item PO #{$orderItemId} bukan bagian dari PO ini.");

            if ((int) data_get($item, 'product_variant_id') !== (int) $orderItem->product_variant_id) {
                throw new PurchaseRuleException("Produk pada baris tidak sesuai dengan item PO #{$orderItemId}.");
            }

            $requested[$orderItem->id] = bcadd($requested[$orderItem->id] ?? '0', (string) data_get($item, 'base_quantity'), 4);
        }

        foreach ($requested as $orderItemId => $qty) {
            if (bccomp($qty, (string) $orderItems->get($orderItemId)->remaining_base_quantity, 4) > 0) {
                throw new PurchaseRuleException("Penerimaan melebihi sisa PO pada item #{$orderItemId}.");
            }
        }

        return $requested;
    }

    /**
     * @param  iterable<\Modules\Purchase\Models\GoodsReceiptItem> $items
     * @return array<int,string>
     */
    private function sumByOrderItem(iterable $items): array
    {
        $sum = [];
        foreach ($items as $item) {
            if ($item->purchase_order_item_id === null) {
                continue;
            }
            $sum[$item->purchase_order_item_id] = bcadd($sum[$item->purchase_order_item_id] ?? '0', (string) $item->base_quantity, 4);
        }

        return $sum;
    }

    /** Kunci baris dan baca ulang (status model dari pemanggil bisa sudah basi). */
    private function lock(GoodsReceipt $receipt, bool $withItems = false): GoodsReceipt
    {
        $query = GoodsReceipt::query()->lockForUpdate();
        if ($withItems) {
            $query->with('items');
        }

        return $query->findOrFail($receipt->getKey());
    }
}
