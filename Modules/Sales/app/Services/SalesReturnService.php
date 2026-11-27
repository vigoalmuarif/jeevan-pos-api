<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Inventory\Enums\MutationType;
use Modules\Inventory\Services\StockService;
use Modules\Product\Models\ProductVariantUnit;
use Modules\Sales\Enums\CashierShiftStatus;
use Modules\Sales\Enums\ReturnFeeBearer;
use Modules\Sales\Enums\SalesPaymentMethod;
use Modules\Sales\Enums\SalesReturnStatus;
use Modules\Sales\Enums\SalesReturnType;
use Modules\Sales\Enums\SalesTransactionStatus;
use Modules\Sales\Models\CashierShift;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnExchangeItem;
use Modules\Sales\Models\SalesReturnItem;
use Modules\Sales\Models\SalesTransaction;
use Modules\Sales\Models\SalesTransactionItem;

class SalesReturnService
{
    public function __construct(
        private readonly SalesDocumentNumberService $numberService,
        private readonly StockService $stockService,
    ) {}

    /**
     * Daftar retur penjualan (mendukung parameter query standar useDataTable: filter[...], sort, page, per_page).
     */
    public function list(array $params = []): LengthAwarePaginator
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $filter = is_array($params['filter'] ?? null) ? $params['filter'] : [];
        $search = trim((string) ($filter['search'] ?? $params['search'] ?? ''));
        $status = (string) ($filter['status'] ?? $params['status'] ?? '');
        $returnType = (string) ($filter['return_type'] ?? $params['return_type'] ?? '');
        $branchId = $filter['branch_id'] ?? $params['branch_id'] ?? null;
        $refundMethod = (string) ($filter['refund_method'] ?? $params['refund_method'] ?? '');

        $query = SalesReturn::query()
            ->where('merchant_id', $merchantId)
            ->with([
                'branch:id,name',
                'warehouse:id,name',
                'transaction:id,transaction_number,grand_total,sold_at',
                'customer:id,code,name,phone',
                'creator:id,name',
                'cashierShift:id,shift_number',
            ])
            ->withCount(['items', 'exchangeItems']);

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($returnType !== '') {
            $query->where('return_type', $returnType);
        }

        if ($branchId !== null && $branchId !== '') {
            $query->where('branch_id', (int) $branchId);
        }

        if ($refundMethod !== '') {
            $query->where('refund_method', $refundMethod);
        }

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(return_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(reason) LIKE ?', [$like])
                    ->orWhereHas('transaction', fn ($t) => $t->whereRaw('LOWER(transaction_number) LIKE ?', [$like]))
                    ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]));
            });
        }

        $sortParam = (string) ($params['sort'] ?? '-returned_at');
        $direction = str_starts_with($sortParam, '-') ? 'desc' : 'asc';
        $column = ltrim($sortParam, '-');
        $allowedSorts = ['return_number', 'total_refund', 'settlement_amount', 'returned_at', 'created_at', 'status'];

        if (in_array($column, $allowedSorts, true)) {
            $query->orderBy($column, $direction)->orderByDesc('id');
        } else {
            $query->orderByDesc('returned_at')->orderByDesc('id');
        }

        $perPage = min(max((int) ($params['per_page'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    public function show(int $id): SalesReturn
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        return SalesReturn::query()
            ->where('merchant_id', $merchantId)
            ->with([
                'branch:id,name',
                'warehouse:id,name',
                'transaction:id,transaction_number,grand_total,sold_at,status',
                'cashierShift:id,shift_number,status',
                'customer:id,code,name,phone',
                'creator:id,name',
                'voider:id,name',
                'items',
                'exchangeItems',
            ])
            ->findOrFail($id);
    }

    /**
     * Daftar transaksi selesai (completed) yang masih memiliki sisa item untuk diretur.
     *
     * @return array<int, array<string, mixed>>
     */
    public function returnableTransactions(array $filters = []): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $limit = min(max((int) ($filters['limit'] ?? 20), 1), 50);

        $query = SalesTransaction::query()
            ->where('merchant_id', $merchantId)
            ->where('status', SalesTransactionStatus::Completed->value)
            ->with([
                'branch:id,name',
                'warehouse:id,name',
                'customer:id,code,name,phone',
                'items.product:id,track_stock',
            ]);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['search'])) {
            $like = '%'.mb_strtolower(trim((string) $filters['search'])).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(transaction_number) LIKE ?', [$like])
                    ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]));
            });
        }

        $transactions = $query->orderByDesc('sold_at')->orderByDesc('id')->limit($limit)->get();
        if ($transactions->isEmpty()) {
            return [];
        }

        $txItemIds = $transactions->flatMap(fn (SalesTransaction $tx) => $tx->items->pluck('id'))->all();

        $returnedByTxItem = empty($txItemIds)
            ? collect()
            : DB::table('sales_return_items')
                ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
                ->where('sales_return_items.merchant_id', $merchantId)
                ->where('sales_returns.merchant_id', $merchantId)
                ->where('sales_returns.status', SalesReturnStatus::Completed->value)
                ->whereNull('sales_returns.deleted_at')
                ->whereIn('sales_return_items.sales_transaction_item_id', $txItemIds)
                ->groupBy('sales_return_items.sales_transaction_item_id')
                ->selectRaw('sales_return_items.sales_transaction_item_id, COALESCE(SUM(sales_return_items.quantity), 0) as returned_qty')
                ->pluck('returned_qty', 'sales_transaction_item_id');

        $result = [];

        foreach ($transactions as $tx) {
            $netItemsTotal = (float) $tx->items->sum(fn (SalesTransactionItem $i) => (float) $i->total);
            $orderDiscountRatio = $netItemsTotal > 0 && (float) $tx->discount_amount > 0
                ? min(1.0, max(0.0, ((float) $tx->grand_total) / $netItemsTotal))
                : 1.0;

            $items = [];
            foreach ($tx->items as $item) {
                $origQty = round((float) $item->quantity, 4);
                $returnedQty = round((float) ($returnedByTxItem[$item->id] ?? 0), 4);
                $remainingQty = round(max(0.0, $origQty - $returnedQty), 4);

                if ($remainingQty <= 0) {
                    continue;
                }

                $netPerUnit = $origQty > 0
                    ? round(((float) $item->total * $orderDiscountRatio) / $origQty, 2)
                    : round((float) $item->unit_price, 2);

                $items[] = [
                    'sales_transaction_item_id' => (int) $item->id,
                    'product_id' => (int) $item->product_id,
                    'product_variant_id' => (int) $item->product_variant_id,
                    'product_variant_unit_id' => (int) $item->product_variant_unit_id,
                    'product_name' => (string) $item->product_name,
                    'variant_name' => $item->variant_name,
                    'sku' => $item->sku,
                    'unit_name' => (string) $item->unit_name,
                    'unit_code' => $item->unit_code,
                    'original_quantity' => $origQty,
                    'returned_quantity' => $returnedQty,
                    'remaining_quantity' => $remainingQty,
                    'unit_price' => round((float) $item->unit_price, 2),
                    'refundable_unit_amount' => $netPerUnit,
                    'track_stock' => $item->product ? (bool) $item->product->track_stock : true,
                ];
            }

            if (empty($items)) {
                continue;
            }

            $result[] = [
                'id' => (int) $tx->id,
                'transaction_number' => (string) $tx->transaction_number,
                'branch_id' => (int) $tx->branch_id,
                'branch_name' => $tx->branch?->name,
                'warehouse_id' => (int) $tx->warehouse_id,
                'warehouse_name' => $tx->warehouse?->name,
                'customer_id' => $tx->customer_id ? (int) $tx->customer_id : null,
                'customer_name' => $tx->customer?->name ?? 'Pelanggan Umum',
                'grand_total' => round((float) $tx->grand_total, 2),
                'sold_at' => $tx->sold_at?->toISOString(),
                'items' => $items,
            ];
        }

        return $result;
    }

    /**
     * Buat dokumen Retur Penjualan baru (mendukung tipe 'refund' maupun 'exchange' / Tukar Barang):
     * - Validasi qty tidak melebihi sisa qty transaksi asli
     * - Untuk tipe 'exchange', validasi & potong stok barang pengganti serta hitung selisih (settlement_amount)
     * - Semua mutasi stok diurutkan secara deterministik (DEC-07) untuk mencegah deadlock
     */
    public function create(array $data): SalesReturn
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $userId = Auth::guard('merchant')->id() ?? Auth::id();
        $returnType = SalesReturnType::tryFrom((string) ($data['return_type'] ?? SalesReturnType::Refund->value));
        if (! $returnType) {
            throw ValidationException::withMessages([
                'return_type' => 'Tipe retur tidak valid.',
            ]);
        }

        $refundMethod = SalesPaymentMethod::tryFrom((string) ($data['refund_method'] ?? SalesPaymentMethod::Cash->value));
        if (! $refundMethod) {
            throw ValidationException::withMessages([
                'refund_method' => 'Metode penyelesaian dana tidak valid.',
            ]);
        }

        return DB::transaction(function () use ($merchantId, $userId, $returnType, $refundMethod, $data) {
            $tx = SalesTransaction::query()
                ->where('merchant_id', $merchantId)
                ->with(['items.product:id,track_stock'])
                ->lockForUpdate()
                ->findOrFail((int) $data['sales_transaction_id']);

            if ($tx->status !== SalesTransactionStatus::Completed) {
                throw ValidationException::withMessages([
                    'sales_transaction_id' => 'Hanya transaksi berstatus selesai (completed) yang dapat diretur.',
                ]);
            }

            $txItemsById = $tx->items->keyBy('id');
            $txItemIds = $txItemsById->keys()->all();

            $alreadyReturnedByItem = DB::table('sales_return_items')
                ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
                ->where('sales_return_items.merchant_id', $merchantId)
                ->where('sales_returns.merchant_id', $merchantId)
                ->where('sales_returns.status', SalesReturnStatus::Completed->value)
                ->whereNull('sales_returns.deleted_at')
                ->whereIn('sales_return_items.sales_transaction_item_id', $txItemIds)
                ->groupBy('sales_return_items.sales_transaction_item_id')
                ->selectRaw('sales_return_items.sales_transaction_item_id, COALESCE(SUM(sales_return_items.quantity), 0) as returned_qty')
                ->pluck('returned_qty', 'sales_transaction_item_id');

            $netItemsTotal = (float) $tx->items->sum(fn (SalesTransactionItem $i) => (float) $i->total);
            $orderDiscountRatio = $netItemsTotal > 0 && (float) $tx->discount_amount > 0
                ? min(1.0, max(0.0, ((float) $tx->grand_total) / $netItemsTotal))
                : 1.0;

            $requestedItems = $data['items'] ?? [];
            if (empty($requestedItems)) {
                throw ValidationException::withMessages([
                    'items' => 'Minimal satu item retur wajib dipilih.',
                ]);
            }

            $seenItemIds = [];
            $preparedLines = [];
            $totalSubtotal = 0.0;
            $totalDiscount = 0.0;
            $totalTax = 0.0;
            $totalRefund = 0.0;
            $totalCogsReversed = 0.0;

            foreach ($requestedItems as $idx => $row) {
                $txItemId = (int) ($row['sales_transaction_item_id'] ?? 0);
                if (isset($seenItemIds[$txItemId])) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.sales_transaction_item_id" => 'Item transaksi tidak boleh duplikat dalam satu dokumen retur.',
                    ]);
                }
                $seenItemIds[$txItemId] = true;

                /** @var SalesTransactionItem|null $origItem */
                $origItem = $txItemsById->get($txItemId);
                if (! $origItem) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.sales_transaction_item_id" => 'Item tidak ditemukan pada transaksi penjualan ini.',
                    ]);
                }

                $qty = round((float) ($row['quantity'] ?? 0), 4);
                $origQty = round((float) $origItem->quantity, 4);
                $returnedQty = round((float) ($alreadyReturnedByItem[$txItemId] ?? 0), 4);
                $remainingQty = round(max(0.0, $origQty - $returnedQty), 4);

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" => 'Qty retur harus lebih dari nol.',
                    ]);
                }

                if ($qty > $remainingQty + 0.00001) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.quantity" => "Qty retur untuk {$origItem->product_name} melebihi sisa yang dapat diretur ({$remainingQty}).",
                    ]);
                }

                $ratio = $origQty > 0 ? ($qty / $origQty) : 0.0;
                $baseQty = round($qty * (float) $origItem->conversion_value, 4);
                $lineSubtotal = round((float) $origItem->subtotal * $ratio, 2);
                $lineDiscount = round((float) $origItem->discount_amount * $ratio, 2);
                $lineTax = round((float) $origItem->tax_amount * $ratio, 2);
                $lineRefund = round(((float) $origItem->total * $orderDiscountRatio) * $ratio, 2);
                $unitCost = round((float) $origItem->unit_cost, 4);
                $lineCogs = round($baseQty * $unitCost, 2);

                $restock = array_key_exists('restock', $row) ? (bool) $row['restock'] : true;
                $trackStock = $origItem->product ? (bool) $origItem->product->track_stock : true;

                $totalSubtotal += $lineSubtotal;
                $totalDiscount += $lineDiscount;
                $totalTax += $lineTax;
                $totalRefund += $lineRefund;
                if ($restock && $trackStock) {
                    $totalCogsReversed += $lineCogs;
                }

                $preparedLines[] = [
                    'sales_transaction_item_id' => $txItemId,
                    'product_id' => (int) $origItem->product_id,
                    'product_variant_id' => (int) $origItem->product_variant_id,
                    'product_variant_unit_id' => (int) $origItem->product_variant_unit_id,
                    'product_name' => (string) $origItem->product_name,
                    'variant_name' => $origItem->variant_name,
                    'sku' => $origItem->sku,
                    'unit_name' => (string) $origItem->unit_name,
                    'unit_code' => $origItem->unit_code,
                    'conversion_value' => (float) $origItem->conversion_value,
                    'quantity' => $qty,
                    'base_quantity' => $baseQty,
                    'unit_price' => round((float) $origItem->unit_price, 2),
                    'unit_cost' => $unitCost,
                    'total_cogs' => $lineCogs,
                    'discount_amount' => $lineDiscount,
                    'tax_amount' => $lineTax,
                    'subtotal' => $lineSubtotal,
                    'total_refund' => $lineRefund,
                    'restock' => $restock,
                    'track_stock' => $trackStock,
                    'notes' => isset($row['notes']) && trim((string) $row['notes']) !== '' ? trim((string) $row['notes']) : null,
                ];
            }

            // Siapkan barang pengganti apabila tipe = exchange (Tukar Barang)
            $preparedExchangeLines = [];
            $exchangeTotal = 0.0;

            if ($returnType === SalesReturnType::Exchange) {
                $requestedExchange = $data['exchange_items'] ?? [];
                if (empty($requestedExchange)) {
                    throw ValidationException::withMessages([
                        'exchange_items' => 'Minimal satu barang pengganti wajib dipilih untuk retur tukar barang.',
                    ]);
                }

                $unitIds = array_values(array_unique(array_map(
                    fn ($r) => (int) ($r['product_variant_unit_id'] ?? 0),
                    $requestedExchange,
                )));

                $unitsById = ProductVariantUnit::query()
                    ->where('merchant_id', $merchantId)
                    ->whereIn('id', $unitIds)
                    ->with([
                        'unit:id,name,code',
                        'variant:id,product_id,name,sku,is_active',
                        'variant.product:id,name,track_stock,is_active',
                    ])
                    ->get()
                    ->keyBy('id');

                foreach ($requestedExchange as $idx => $exRow) {
                    $vuId = (int) ($exRow['product_variant_unit_id'] ?? 0);
                    /** @var ProductVariantUnit|null $vu */
                    $vu = $unitsById->get($vuId);
                    if (! $vu || ! $vu->variant || ! $vu->variant->product) {
                        throw ValidationException::withMessages([
                            "exchange_items.{$idx}.product_variant_unit_id" => 'Barang pengganti tidak ditemukan.',
                        ]);
                    }

                    $qty = round((float) ($exRow['quantity'] ?? 0), 4);
                    if ($qty <= 0) {
                        throw ValidationException::withMessages([
                            "exchange_items.{$idx}.quantity" => 'Qty barang pengganti harus lebih dari nol.',
                        ]);
                    }

                    $conversion = (float) ($vu->conversion_value ?: 1);
                    $baseQty = round($qty * $conversion, 4);
                    $unitPrice = array_key_exists('unit_price', $exRow) && $exRow['unit_price'] !== null
                        ? round((float) $exRow['unit_price'], 2)
                        : round((float) $vu->default_price, 2);
                    $lineTotal = round($qty * $unitPrice, 2);
                    $exchangeTotal += $lineTotal;

                    $preparedExchangeLines[] = [
                        'product_id' => (int) $vu->variant->product_id,
                        'product_variant_id' => (int) $vu->product_variant_id,
                        'product_variant_unit_id' => (int) $vu->id,
                        'product_name' => (string) $vu->variant->product->name,
                        'variant_name' => $vu->variant->name,
                        'sku' => $vu->variant->sku,
                        'unit_name' => (string) ($vu->unit?->name ?? 'Pcs'),
                        'unit_code' => $vu->unit?->code,
                        'conversion_value' => $conversion,
                        'quantity' => $qty,
                        'base_quantity' => $baseQty,
                        'unit_price' => $unitPrice,
                        'unit_cost' => 0.0,
                        'total_cogs' => 0.0,
                        'subtotal' => $lineTotal,
                        'total' => $lineTotal,
                        'track_stock' => (bool) $vu->variant->product->track_stock,
                        'notes' => isset($exRow['notes']) && trim((string) $exRow['notes']) !== '' ? trim((string) $exRow['notes']) : null,
                    ];
                }
            }

            $totalRefund = round($totalRefund, 2);
            $exchangeTotal = round($exchangeTotal, 2);
            $returnFee = round(max(0.0, (float) ($data['return_fee'] ?? 0)), 2);
            $returnFeeBearer = ReturnFeeBearer::tryFrom((string) ($data['return_fee_bearer'] ?? ReturnFeeBearer::Customer->value))
                ?? ReturnFeeBearer::Customer;

            if ($returnType === SalesReturnType::Refund && $returnFeeBearer === ReturnFeeBearer::Customer && $returnFee > $totalRefund) {
                throw ValidationException::withMessages([
                    'return_fee' => 'Biaya retur yang ditanggung pelanggan tidak boleh melebihi total nilai barang yang diretur.',
                ]);
            }

            // Konvensi: negatif = kasir keluarkan uang (refund / biaya ditanggung toko), positif = pelanggan bayar tambahan, 0 = impas
            $baseSettlement = $returnType === SalesReturnType::Exchange
                ? round($exchangeTotal - $totalRefund, 2)
                : round(-1 * $totalRefund, 2);
            $feeImpact = $returnFee > 0
                ? ($returnFeeBearer === ReturnFeeBearer::Customer ? $returnFee : (-1 * $returnFee))
                : 0.0;
            $settlementAmount = round($baseSettlement + $feeImpact, 2);

            // Hubungkan shift aktif bila ada pergerakan kas tunai (settlement_amount != 0 & refund_method = cash) atau diminta eksplisit
            $shiftId = null;
            if (! empty($data['cashier_shift_id'])) {
                $shift = CashierShift::query()
                    ->where('merchant_id', $merchantId)
                    ->where('branch_id', (int) $tx->branch_id)
                    ->where('status', CashierShiftStatus::Open->value)
                    ->find((int) $data['cashier_shift_id']);
                $shiftId = $shift?->id;
            } elseif ($refundMethod->isCash() && abs($settlementAmount) > 0.0 && $userId) {
                $shift = CashierShift::query()
                    ->where('merchant_id', $merchantId)
                    ->where('branch_id', (int) $tx->branch_id)
                    ->where('user_id', $userId)
                    ->where('status', CashierShiftStatus::Open->value)
                    ->orderByDesc('opened_at')
                    ->first();
                $shiftId = $shift?->id;
            }

            $now = now();
            $returnNumber = $this->numberService->nextReturnNumber();

            $salesReturn = SalesReturn::query()->create([
                'merchant_id' => $merchantId,
                'branch_id' => (int) $tx->branch_id,
                'warehouse_id' => (int) $tx->warehouse_id,
                'sales_transaction_id' => (int) $tx->id,
                'cashier_shift_id' => $shiftId,
                'customer_id' => $tx->customer_id,
                'return_number' => $returnNumber,
                'status' => SalesReturnStatus::Completed->value,
                'return_type' => $returnType->value,
                'refund_method' => $refundMethod->value,
                'reason' => trim((string) $data['reason']),
                'subtotal' => round($totalSubtotal, 2),
                'discount_amount' => round($totalDiscount, 2),
                'tax_amount' => round($totalTax, 2),
                'total_refund' => $totalRefund,
                'exchange_total' => $exchangeTotal,
                'return_fee' => $returnFee,
                'return_fee_bearer' => $returnFeeBearer->value,
                'settlement_amount' => $settlementAmount,
                'total_cogs_reversed' => round($totalCogsReversed, 2),
                'total_cogs_exchange' => 0,
                'returned_at' => $now,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // DEC-07: Gabungkan seluruh mutasi stok (restock masuk + barang pengganti keluar) dalam 1 urutan deterministik
            // product_variant_id ASC, lalu 'in' (priority 0) sebelum 'out' (priority 1) agar aman dari deadlock & stok sementara.
            $stockOps = [];
            foreach ($preparedLines as $idx => $line) {
                if ($line['restock'] && $line['track_stock']) {
                    $stockOps[] = [
                        'variant_id' => (int) $line['product_variant_id'],
                        'priority' => 0,
                        'kind' => 'restock',
                        'index' => $idx,
                    ];
                }
            }
            foreach ($preparedExchangeLines as $idx => $exLine) {
                if ($exLine['track_stock']) {
                    $stockOps[] = [
                        'variant_id' => (int) $exLine['product_variant_id'],
                        'priority' => 1,
                        'kind' => 'exchange_out',
                        'index' => $idx,
                    ];
                }
            }

            usort($stockOps, fn (array $a, array $b) => [$a['variant_id'], $a['priority'], $a['index']] <=> [$b['variant_id'], $b['priority'], $b['index']]);

            $totalCogsExchange = 0.0;
            foreach ($stockOps as $op) {
                if ($op['kind'] === 'restock') {
                    $line = $preparedLines[$op['index']];
                    $this->stockService->recordMutation(
                        warehouseId: (int) $tx->warehouse_id,
                        productVariantId: (int) $line['product_variant_id'],
                        baseQuantity: (float) $line['base_quantity'],
                        mutationType: MutationType::SalesReturn->value,
                        incomingUnitCost: (float) $line['unit_cost'],
                        referenceType: 'sales_return',
                        referenceId: (int) $salesReturn->id,
                        referenceNumber: $returnNumber,
                        notes: 'Retur penjualan '.$returnNumber.' (Ref: '.$tx->transaction_number.')',
                        mutatedAt: $now,
                    );
                } else {
                    $exIdx = $op['index'];
                    $exLine = $preparedExchangeLines[$exIdx];
                    $mutation = $this->stockService->recordMutation(
                        warehouseId: (int) $tx->warehouse_id,
                        productVariantId: (int) $exLine['product_variant_id'],
                        baseQuantity: -1 * (float) $exLine['base_quantity'],
                        mutationType: MutationType::SalesOut->value,
                        incomingUnitCost: null,
                        referenceType: 'sales_return_exchange',
                        referenceId: (int) $salesReturn->id,
                        referenceNumber: $returnNumber,
                        notes: 'Barang pengganti retur '.$returnNumber.' (Ref: '.$tx->transaction_number.')',
                        mutatedAt: $now,
                    );

                    $unitCost = round((float) ($mutation->unit_cost ?? 0), 4);
                    $lineCogs = round((float) $exLine['base_quantity'] * $unitCost, 2);
                    $preparedExchangeLines[$exIdx]['unit_cost'] = $unitCost;
                    $preparedExchangeLines[$exIdx]['total_cogs'] = $lineCogs;
                    $totalCogsExchange += $lineCogs;
                }
            }

            if ($totalCogsExchange > 0) {
                $salesReturn->update(['total_cogs_exchange' => round($totalCogsExchange, 2)]);
            }

            $insertRows = array_map(fn (array $line) => [
                'merchant_id' => $merchantId,
                'sales_return_id' => $salesReturn->id,
                'sales_transaction_item_id' => $line['sales_transaction_item_id'],
                'product_id' => $line['product_id'],
                'product_variant_id' => $line['product_variant_id'],
                'product_variant_unit_id' => $line['product_variant_unit_id'],
                'product_name' => $line['product_name'],
                'variant_name' => $line['variant_name'],
                'sku' => $line['sku'],
                'unit_name' => $line['unit_name'],
                'unit_code' => $line['unit_code'],
                'conversion_value' => $line['conversion_value'],
                'quantity' => $line['quantity'],
                'base_quantity' => $line['base_quantity'],
                'unit_price' => $line['unit_price'],
                'unit_cost' => $line['unit_cost'],
                'total_cogs' => $line['total_cogs'],
                'discount_amount' => $line['discount_amount'],
                'tax_amount' => $line['tax_amount'],
                'subtotal' => $line['subtotal'],
                'total_refund' => $line['total_refund'],
                'restock' => $line['restock'],
                'notes' => $line['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $preparedLines);

            SalesReturnItem::query()->insert($insertRows);

            if (! empty($preparedExchangeLines)) {
                $exchangeRows = array_map(fn (array $exLine) => [
                    'merchant_id' => $merchantId,
                    'sales_return_id' => $salesReturn->id,
                    'product_id' => $exLine['product_id'],
                    'product_variant_id' => $exLine['product_variant_id'],
                    'product_variant_unit_id' => $exLine['product_variant_unit_id'],
                    'product_name' => $exLine['product_name'],
                    'variant_name' => $exLine['variant_name'],
                    'sku' => $exLine['sku'],
                    'unit_name' => $exLine['unit_name'],
                    'unit_code' => $exLine['unit_code'],
                    'conversion_value' => $exLine['conversion_value'],
                    'quantity' => $exLine['quantity'],
                    'base_quantity' => $exLine['base_quantity'],
                    'unit_price' => $exLine['unit_price'],
                    'unit_cost' => $exLine['unit_cost'],
                    'total_cogs' => $exLine['total_cogs'],
                    'subtotal' => $exLine['subtotal'],
                    'total' => $exLine['total'],
                    'notes' => $exLine['notes'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $preparedExchangeLines);

                SalesReturnExchangeItem::query()->insert($exchangeRows);
            }

            return $this->show((int) $salesReturn->id);
        });
    }

    /**
     * Batalkan (void) dokumen Retur Penjualan dan balikkan mutasi stok (baik restock retur maupun barang pengganti).
     */
    public function void(int $id, string $reason): SalesReturn
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $userId = Auth::guard('merchant')->id() ?? Auth::id();

        return DB::transaction(function () use ($merchantId, $id, $reason, $userId) {
            $salesReturn = SalesReturn::query()
                ->where('merchant_id', $merchantId)
                ->with(['items.product:id,track_stock', 'exchangeItems.product:id,track_stock'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($salesReturn->status !== SalesReturnStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya retur penjualan berstatus selesai yang dapat dibatalkan.',
                ]);
            }

            $now = now();

            // DEC-07: Gabungkan semua pembalikan stok secara deterministik (product_variant_id ASC, masuk dulu sebelum keluar).
            $ops = [];
            foreach ($salesReturn->exchangeItems as $exItem) {
                $trackStock = $exItem->product ? (bool) $exItem->product->track_stock : true;
                if ($trackStock) {
                    $ops[] = [
                        'variant_id' => (int) $exItem->product_variant_id,
                        'priority' => 0,
                        'kind' => 'exchange_restore',
                        'id' => (int) $exItem->id,
                        'model' => $exItem,
                    ];
                }
            }
            foreach ($salesReturn->items as $item) {
                $trackStock = $item->product ? (bool) $item->product->track_stock : true;
                if ($item->restock && $trackStock) {
                    $ops[] = [
                        'variant_id' => (int) $item->product_variant_id,
                        'priority' => 1,
                        'kind' => 'restock_reverse',
                        'id' => (int) $item->id,
                        'model' => $item,
                    ];
                }
            }

            usort($ops, fn (array $a, array $b) => [$a['variant_id'], $a['priority'], $a['id']] <=> [$b['variant_id'], $b['priority'], $b['id']]);

            foreach ($ops as $op) {
                if ($op['kind'] === 'exchange_restore') {
                    /** @var SalesReturnExchangeItem $exItem */
                    $exItem = $op['model'];
                    $this->stockService->recordMutation(
                        warehouseId: (int) $salesReturn->warehouse_id,
                        productVariantId: (int) $exItem->product_variant_id,
                        baseQuantity: (float) $exItem->base_quantity,
                        mutationType: MutationType::SalesVoid->value,
                        incomingUnitCost: (float) $exItem->unit_cost,
                        referenceType: 'sales_return_exchange',
                        referenceId: (int) $salesReturn->id,
                        referenceNumber: $salesReturn->return_number,
                        notes: 'Void tukar barang retur: '.trim($reason),
                        mutatedAt: $now,
                    );
                } else {
                    /** @var SalesReturnItem $item */
                    $item = $op['model'];
                    $this->stockService->recordMutation(
                        warehouseId: (int) $salesReturn->warehouse_id,
                        productVariantId: (int) $item->product_variant_id,
                        baseQuantity: -1 * (float) $item->base_quantity,
                        mutationType: MutationType::SalesReturnVoid->value,
                        incomingUnitCost: null,
                        referenceType: 'sales_return',
                        referenceId: (int) $salesReturn->id,
                        referenceNumber: $salesReturn->return_number,
                        notes: 'Void retur penjualan: '.trim($reason),
                        mutatedAt: $now,
                        reversedUnitCost: (float) $item->unit_cost,
                    );
                }
            }

            $salesReturn->update([
                'status' => SalesReturnStatus::Voided->value,
                'voided_at' => $now,
                'voided_by' => $userId,
                'void_reason' => trim($reason),
                'updated_by' => $userId,
            ]);

            return $this->show((int) $salesReturn->id);
        });
    }
}
