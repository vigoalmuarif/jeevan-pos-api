<?php

namespace Modules\Purchase\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Inventory\Enums\MutationType;
use Modules\Inventory\Services\StockService;
use Modules\Purchase\Enums\DocumentStatus;
use Modules\Purchase\Enums\ReturnReason;
use Modules\Purchase\Enums\SettlementMethod;
use Modules\Purchase\Exceptions\PurchaseRuleException;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\PurchaseReturn;
use Modules\Purchase\Models\PurchaseReturnItem;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Service untuk mengelola siklus hidup Dokumen Retur Pembelian (Purchase Return - DEC-10).
 */
class PurchaseReturnService
{
    private const HEADER_FIELDS = [
        'branch_id', 'supplier_id', 'warehouse_id', 'goods_receipt_id',
        'purchase_invoice_id', 'return_date', 'settlement_method', 'reason_code', 'notes',
    ];

    public function __construct(
        private readonly PurchaseDocumentNumberService $numbers,
        private readonly StockService $stockService,
    ) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $config = new DataTableConfig(
            allowedFilters: [
                AllowedFilter::exact('status'),
                AllowedFilter::exact('supplier_id'),
                AllowedFilter::exact('warehouse_id'),
                AllowedFilter::exact('branch_id'),
                AllowedFilter::exact('settlement_method'),
            ],
            allowedSorts: ['document_number', 'return_date', 'grand_total', 'created_at'],
            searchableColumns: ['document_number', 'notes'],
            defaultSort: '-return_date',
        );

        return ServerSideTable::paginate(
            PurchaseReturn::query()
                ->with([
                    'supplier:id,name,code',
                    'warehouse:id,name,code',
                    'branch:id,name',
                    'goodsReceipt:id,document_number,receipt_date',
                ])
                ->withCount('items'),
            $config,
            $request
        );
    }

    public function find(int $id): PurchaseReturn
    {
        return PurchaseReturn::query()
            ->with([
                'supplier:id,name,code,payment_term_days,phone,email',
                'warehouse:id,name,code',
                'branch:id,name',
                'goodsReceipt:id,document_number,receipt_date',
                'purchaseInvoice:id,document_number',
                'items.productVariant.product:id,name',
                'items.unit:id,name,code',
                'items.goodsReceiptItem:id,unit_quantity,base_quantity,unit_price,unit_cost',
            ])
            ->findOrFail($id);
    }

    public function create(array $data, array $items): PurchaseReturn
    {
        return DB::transaction(function () use ($data, $items) {
            $merchantId = MerchantContext::id();
            abort_unless($merchantId, 403, 'Merchant context is required.');

            $receipt = $this->loadReceiptForReturn((int) $data['goods_receipt_id']);
            $prepared = $this->prepareItems($receipt, $items);

            $return = new PurchaseReturn();
            $return->fill(array_merge(
                Arr::only($data, self::HEADER_FIELDS),
                [
                    'supplier_id' => $receipt->supplier_id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'branch_id' => $data['branch_id'] ?? $receipt->branch_id,
                    'purchase_invoice_id' => $receipt->purchase_invoice_id,
                    'document_number' => $this->numbers->generate('purchase_return'),
                    'subtotal' => $prepared['subtotal'],
                    'tax_amount' => $prepared['tax_amount'],
                    'grand_total' => $prepared['grand_total'],
                ]
            ));
            $return->save();

            $return->items()->createMany($prepared['items']);

            return $return->load('items');
        });
    }

    public function update(PurchaseReturn|int $return, array $data, array $items): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $data, $items) {
            $return = $this->lock($return);

            if (! $return->status->isEditable()) {
                throw new PurchaseRuleException('Hanya retur berstatus draft yang dapat diubah.');
            }

            $receipt = $this->loadReceiptForReturn((int) $data['goods_receipt_id']);
            $prepared = $this->prepareItems($receipt, $items, ignoreReturnId: $return->id);

            $return->fill(array_merge(
                Arr::only($data, self::HEADER_FIELDS),
                [
                    'supplier_id' => $receipt->supplier_id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'branch_id' => $data['branch_id'] ?? $receipt->branch_id,
                    'purchase_invoice_id' => $receipt->purchase_invoice_id,
                    'subtotal' => $prepared['subtotal'],
                    'tax_amount' => $prepared['tax_amount'],
                    'grand_total' => $prepared['grand_total'],
                ]
            ))->save();

            $return->items()->delete();
            $return->items()->createMany($prepared['items']);

            return $return->load('items');
        });
    }

    public function delete(PurchaseReturn|int $return): void
    {
        DB::transaction(function () use ($return) {
            $return = $this->lock($return);

            if (! $return->status->isEditable()) {
                throw new PurchaseRuleException('Hanya retur berstatus draft yang dapat dihapus. Retur yang sudah diposting dibatalkan (void).');
            }

            $return->delete();
        });
    }

    public function post(PurchaseReturn|int $return): PurchaseReturn
    {
        return DB::transaction(function () use ($return) {
            $return = $this->lock($return, withItems: true);

            if ($return->status !== DocumentStatus::Draft) {
                throw new PurchaseRuleException('Hanya retur berstatus draft yang dapat diposting.');
            }

            if ($return->items->isEmpty()) {
                throw new PurchaseRuleException('Dokumen retur tidak memiliki item untuk dikembalikan.');
            }

            // Validasi sisa kuantitas receipt kembali saat transaksi terkunci
            $receipt = $this->loadReceiptForReturn($return->goods_receipt_id);
            $this->validateReturnableQuantities($receipt, $return->items, ignoreReturnId: $return->id);

            // DEC-07: Urutkan deterministik (product_variant_id asc, id asc) sebelum mengunci baris stok
            $items = $return->items->sortBy([['product_variant_id', 'asc'], ['id', 'asc']]);

            foreach ($items as $item) {
                // DEC-08 & DEC-10: Berikan reversedUnitCost sebesar HPP masuk aslinya
                $this->stockService->recordMutation(
                    warehouseId: (int) $return->warehouse_id,
                    productVariantId: (int) $item->product_variant_id,
                    baseQuantity: -(float) $item->base_quantity,
                    mutationType: MutationType::PurchaseReturn->value,
                    referenceType: 'purchase_return',
                    referenceId: (int) $return->id,
                    referenceNumber: $return->document_number,
                    notes: "Retur: " . ($item->reason ?: $return->notes),
                    reversedUnitCost: (float) $item->unit_cost,
                );
            }

            // Jika settlement_method adalah potong faktur dan faktur sudah ada, hubungkan
            if ($return->settlement_method === SettlementMethod::InvoiceDeduction && $receipt->purchase_invoice_id) {
                $return->purchase_invoice_id = $receipt->purchase_invoice_id;
            }

            $return->forceFill([
                'status' => DocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => auth('merchant')->id(),
            ])->save();

            return $return;
        });
    }

    public function void(PurchaseReturn|int $return, string $reason): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $reason) {
            $return = $this->lock($return, withItems: true);

            if (! $return->status->canVoid()) {
                throw new PurchaseRuleException('Hanya retur berstatus diposting yang dapat di-void.');
            }

            // DEC-07: Urutkan deterministik
            $items = $return->items->sortBy([['product_variant_id', 'asc'], ['id', 'asc']]);

            foreach ($items as $item) {
                // Kembalikan stok ke gudang (mutasi masuk pembalik retur)
                $this->stockService->recordMutation(
                    warehouseId: (int) $return->warehouse_id,
                    productVariantId: (int) $item->product_variant_id,
                    baseQuantity: (float) $item->base_quantity,
                    mutationType: MutationType::PurchaseReturnVoid->value,
                    referenceType: 'purchase_return',
                    referenceId: (int) $return->id,
                    referenceNumber: $return->document_number,
                    notes: "Void Retur {$return->document_number}: {$reason}",
                    incomingUnitCost: (float) $item->unit_cost,
                );
            }

            $return->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => auth('merchant')->id(),
                'void_reason' => $reason,
            ])->save();

            return $return;
        });
    }

    /**
     * Mengambil daftar GoodsReceipt yang sudah posted dan masih memiliki item untuk diretur.
     */
    public function getReturnableReceipts(Request $request): Collection
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $query = GoodsReceipt::query()
            ->where('status', DocumentStatus::Posted->value)
            ->with(['supplier:id,name,code', 'warehouse:id,name,code'])
            ->orderByDesc('receipt_date');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($supplierId = $request->input('supplier_id')) {
            $query->where('supplier_id', $supplierId);
        }

        if ($warehouseId = $request->input('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->limit(50)->get(['id', 'document_number', 'supplier_id', 'warehouse_id', 'receipt_date', 'created_at', 'grand_total']);
    }

    /**
     * Mengambil item GoodsReceipt beserta sisa kuantitas yang masih dapat diretur.
     */
    public function getReceiptReturnableItems(int $receiptId): array
    {
        $receipt = $this->loadReceiptForReturn($receiptId);
        $receipt->load(['items.productVariant.product:id,name', 'items.unit:id,name,code']);

        $returnedQuantities = $this->getReturnedQuantities($receipt->id);

        return $receipt->items->map(function (GoodsReceiptItem $item) use ($returnedQuantities) {
            $alreadyReturned = $returnedQuantities[$item->id] ?? 0.0;
            $remainingUnitQty = max(0, (float) $item->unit_quantity - $alreadyReturned);
            $conversionRate = (float) $item->conversion_rate;

            return [
                'goods_receipt_item_id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->productVariant?->product?->name ?? 'Produk',
                'variant_name' => $item->productVariant?->name ?? '',
                'sku' => $item->productVariant?->sku ?? '',
                'unit_id' => $item->unit_id,
                'unit_name' => $item->unit?->name ?? '',
                'conversion_rate' => $conversionRate,
                'received_unit_quantity' => (float) $item->unit_quantity,
                'already_returned_unit_quantity' => $alreadyReturned,
                'available_unit_quantity' => round($remainingUnitQty, 4),
                'unit_price' => (float) $item->unit_price,
                'unit_cost' => (float) $item->unit_cost,
                'tax_rate' => (float) $item->tax_rate,
            ];
        })->values()->toArray();
    }

    private function loadReceiptForReturn(int $receiptId): GoodsReceipt
    {
        $receipt = GoodsReceipt::query()->with('items')->findOrFail($receiptId);

        if ($receipt->status !== DocumentStatus::Posted) {
            throw new PurchaseRuleException('Hanya penerimaan berstatus Posted yang dapat diretur.');
        }

        return $receipt;
    }

    private function lock(PurchaseReturn|int $return, bool $withItems = false): PurchaseReturn
    {
        $id = $return instanceof PurchaseReturn ? $return->id : $return;
        $query = PurchaseReturn::query()->lockForUpdate();

        if ($withItems) {
            $query->with('items');
        }

        return $query->findOrFail($id);
    }

    private function prepareItems(GoodsReceipt $receipt, array $itemsInput, ?int $ignoreReturnId = null): array
    {
        if (empty($itemsInput)) {
            throw ValidationException::withMessages(['items' => 'Retur harus memiliki minimal satu item.']);
        }

        $receiptItems = $receipt->items->keyBy('id');
        $returnedQuantities = $this->getReturnedQuantities($receipt->id, $ignoreReturnId);

        $prepared = [];
        $subtotal = '0';
        $taxAmount = '0';
        $grandTotal = '0';

        foreach ($itemsInput as $index => $line) {
            $receiptItemId = (int) ($line['goods_receipt_item_id'] ?? 0);
            $grItem = $receiptItems->get($receiptItemId);

            if (! $grItem) {
                throw ValidationException::withMessages([
                    "items.{$index}.goods_receipt_item_id" => 'Item tidak ditemukan pada dokumen penerimaan yang dipilih.',
                ]);
            }

            $unitQty = round((float) ($line['unit_quantity'] ?? 0), 4);
            if ($unitQty <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_quantity" => 'Jumlah retur harus lebih besar dari 0.',
                ]);
            }

            $alreadyReturned = $returnedQuantities[$grItem->id] ?? 0.0;
            $maxReturnable = round((float) $grItem->unit_quantity - $alreadyReturned, 4);

            if ($unitQty > $maxReturnable) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_quantity" => "Jumlah retur ({$unitQty}) melebihi sisa penerimaan yang dapat diretur ({$maxReturnable}).",
                ]);
            }

            $conversionRate = (float) $grItem->conversion_rate;
            $baseQty = round($unitQty * $conversionRate, 4);
            $unitPrice = (float) $grItem->unit_price;
            $lineSubtotal = round($unitQty * $unitPrice, 4);
            $taxRate = (float) $grItem->tax_rate;
            $lineTax = round($lineSubtotal * ($taxRate / 100), 4);
            $lineTotal = round($lineSubtotal + $lineTax, 4);

            $prepared[] = [
                'goods_receipt_item_id' => $grItem->id,
                'product_variant_id' => $grItem->product_variant_id,
                'unit_id' => $grItem->unit_id,
                'unit_quantity' => $unitQty,
                'conversion_rate' => $conversionRate,
                'base_quantity' => $baseQty,
                'unit_price' => $unitPrice,
                'unit_cost' => (float) $grItem->unit_cost,
                'subtotal' => $lineSubtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
                'reason' => $line['reason'] ?? null,
            ];

            $subtotal = bcadd($subtotal, (string) $lineSubtotal, 4);
            $taxAmount = bcadd($taxAmount, (string) $lineTax, 4);
            $grandTotal = bcadd($grandTotal, (string) $lineTotal, 4);
        }

        return [
            'items' => $prepared,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'grand_total' => $grandTotal,
        ];
    }

    private function validateReturnableQuantities(GoodsReceipt $receipt, Collection $items, ?int $ignoreReturnId = null): void
    {
        $returnedQuantities = $this->getReturnedQuantities($receipt->id, $ignoreReturnId);
        $receiptItems = $receipt->items->keyBy('id');

        foreach ($items as $item) {
            $grItem = $receiptItems->get($item->goods_receipt_item_id);
            if (! $grItem) {
                throw new PurchaseRuleException('Item penerimaan tidak valid.');
            }

            $alreadyReturned = $returnedQuantities[$grItem->id] ?? 0.0;
            $maxReturnable = round((float) $grItem->unit_quantity - $alreadyReturned, 4);

            if ((float) $item->unit_quantity > $maxReturnable) {
                throw new PurchaseRuleException("Jumlah retur untuk item {$grItem->id} melebihi sisa yang dapat diretur.");
            }
        }
    }

    /**
     * Menghitung total kuantitas yang sudah diretur (dari draft dan posted return) per goods_receipt_item.
     */
    private function getReturnedQuantities(int $receiptId, ?int $ignoreReturnId = null): array
    {
        $query = DB::table('purchase_return_items as pri')
            ->join('purchase_returns as pr', 'pr.id', '=', 'pri.purchase_return_id')
            ->where('pr.goods_receipt_id', $receiptId)
            ->whereIn('pr.status', [DocumentStatus::Draft->value, DocumentStatus::Posted->value])
            ->whereNull('pr.deleted_at');

        if ($ignoreReturnId !== null) {
            $query->where('pr.id', '<>', $ignoreReturnId);
        }

        return $query
            ->groupBy('pri.goods_receipt_item_id')
            ->selectRaw('pri.goods_receipt_item_id, SUM(pri.unit_quantity) as total_returned')
            ->pluck('total_returned', 'goods_receipt_item_id')
            ->map(fn ($val) => (float) $val)
            ->toArray();
    }
}

