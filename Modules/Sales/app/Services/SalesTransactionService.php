<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Inventory\Enums\MutationType;
use Modules\Inventory\Services\StockService;
use Modules\Product\Models\ModifierOption;
use Modules\Product\Models\ProductVariantUnit;
use Modules\Product\Services\PriceResolverService;
use Modules\Sales\Enums\CashierShiftStatus;
use Modules\Sales\Enums\SalesDiscountType;
use Modules\Sales\Enums\SalesOrderType;
use Modules\Sales\Enums\SalesPaymentMethod;
use Modules\Sales\Enums\SalesTransactionStatus;
use Modules\Sales\Models\CashierShift;
use Modules\Sales\Models\SalesPayment;
use Modules\Sales\Models\SalesTransaction;
use Modules\Sales\Models\SalesTransactionItem;
use Modules\Sales\Models\SalesTransactionItemModifier;

class SalesTransactionService
{
    public function __construct(
        private readonly SalesDocumentNumberService $numberService,
        private readonly PosCatalogService $catalogService,
        private readonly PriceResolverService $priceResolver,
        private readonly StockService $stockService,
        private readonly PosConfigResolver $configResolver,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $query = SalesTransaction::query()
            ->where('merchant_id', $merchantId)
            ->with([
                'branch:id,name',
                'warehouse:id,name',
                'customer:id,code,name,phone',
                'creator:id,name',
                'cashierShift:id,shift_number',
                'payments',
            ])
            ->withCount('items');

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        } else {
            // Default riwayat penjualan menampilkan transaksi completed & voided (bukan held).
            $query->whereIn('status', [
                SalesTransactionStatus::Completed->value,
                SalesTransactionStatus::Voided->value,
            ]);
        }

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['cashier_shift_id'])) {
            $query->where('cashier_shift_id', (int) $filters['cashier_shift_id']);
        }

        if (! empty($filters['order_type']) && Schema::hasColumn('sales_transactions', 'order_type')) {
            $query->where('order_type', (string) $filters['order_type']);
        }

        if (! empty($filters['sales_channel']) && Schema::hasColumn('sales_transactions', 'sales_channel')) {
            $query->where('sales_channel', trim((string) $filters['sales_channel']));
        }

        if (! empty($filters['payment_method'])) {
            $method = (string) $filters['payment_method'];
            $query->whereHas('payments', fn ($p) => $p->where('payment_method', $method));
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $hasQueueCol = Schema::hasColumn('sales_transactions', 'queue_number');
            $hasChannelCol = Schema::hasColumn('sales_transactions', 'sales_channel');
            $hasRefCol = Schema::hasColumn('sales_transactions', 'channel_order_ref');
            $query->where(function ($q) use ($search, $hasQueueCol, $hasChannelCol, $hasRefCol) {
                $q->where('transaction_number', 'like', "%{$search}%")
                    ->orWhere('hold_label', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                if ($hasQueueCol) {
                    $q->orWhere('queue_number', 'like', "%{$search}%");
                }
                if ($hasChannelCol) {
                    $q->orWhere('sales_channel', 'like', "%{$search}%");
                }
                if ($hasRefCol) {
                    $q->orWhere('channel_order_ref', 'like', "%{$search}%");
                }
            });
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay());
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Ringkasan omzet & jumlah transaksi selesai per channel (order_type + sales_channel).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{order_type: string, sales_channel: ?string, label: string, count: int, total: float}>
     */
    public function channelSummary(array $filters = []): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        if (! Schema::hasColumn('sales_transactions', 'order_type')) {
            return [];
        }

        $hasSalesChannel = Schema::hasColumn('sales_transactions', 'sales_channel');
        $query = DB::table('sales_transactions')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->where('status', SalesTransactionStatus::Completed->value);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['cashier_shift_id'])) {
            $query->where('cashier_shift_id', (int) $filters['cashier_shift_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay());
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay());
        }

        $selectCols = $hasSalesChannel
            ? ['order_type', 'sales_channel', DB::raw('COUNT(*) as tx_count'), DB::raw('COALESCE(SUM(grand_total), 0) as total_amount')]
            : ['order_type', DB::raw('COUNT(*) as tx_count'), DB::raw('COALESCE(SUM(grand_total), 0) as total_amount')];

        $groupCols = $hasSalesChannel ? ['order_type', 'sales_channel'] : ['order_type'];

        return $query
            ->select($selectCols)
            ->groupBy($groupCols)
            ->orderByDesc('total_amount')
            ->get()
            ->map(function ($row) use ($hasSalesChannel) {
                $typeVal = (string) ($row->order_type ?? 'retail');
                $typeLabel = SalesOrderType::tryFrom($typeVal)?->label() ?? $typeVal;
                $channel = $hasSalesChannel && ! empty($row->sales_channel) ? (string) $row->sales_channel : null;

                return [
                    'order_type' => $typeVal,
                    'sales_channel' => $channel,
                    'label' => $channel ? "{$typeLabel} · {$channel}" : $typeLabel,
                    'count' => (int) $row->tx_count,
                    'total' => round((float) $row->total_amount, 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, SalesTransaction>
     */
    public function listHeld(?int $branchId = null): Collection
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $itemRelation = Schema::hasTable('sales_transaction_item_modifiers') ? 'items.modifiers' : 'items';
        $relations = [
            'branch:id,name',
            'customer:id,code,name,phone',
            'creator:id,name',
            $itemRelation,
        ];
        if (Schema::hasTable('dining_tables')) {
            $relations[] = 'diningTable:id,code,name';
        }

        $query = SalesTransaction::query()
            ->where('merchant_id', $merchantId)
            ->where('status', SalesTransactionStatus::Held->value)
            ->with($relations);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
    }

    public function show(int $id): SalesTransaction
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $itemRelation = Schema::hasTable('sales_transaction_item_modifiers') ? 'items.modifiers' : 'items';
        $relations = [
            'branch:id,name',
            'warehouse:id,name',
            'cashierShift:id,shift_number,status',
            'customer:id,code,name,phone',
            'creator:id,name',
            'voider:id,name',
            $itemRelation,
            'payments',
        ];
        if (Schema::hasTable('dining_tables')) {
            $relations[] = 'diningTable:id,code,name';
        }

        $tx = SalesTransaction::query()
            ->where('merchant_id', $merchantId)
            ->with($relations)
            ->findOrFail($id);

        if ($tx->status === SalesTransactionStatus::Held) {
            app(KitchenDisplayService::class)->attachSentKitchenQuantities($tx);
        }

        return $tx;
    }

    /**
     * Buat transaksi POS baru (status 'completed' untuk checkout atau 'held' untuk park bill).
     * Mendukung Idempotency-Key untuk mencegah duplikasi transaksi & potong stok ganda.
     */
    public function create(array $data, ?string $idempotencyKey = null): SalesTransaction
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $normalizedKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? substr(trim($idempotencyKey), 0, 100)
            : null;

        $targetStatus = ($data['status'] ?? SalesTransactionStatus::Completed->value) === SalesTransactionStatus::Held->value
            ? SalesTransactionStatus::Held
            : SalesTransactionStatus::Completed;

        $userId = Auth::guard('merchant')->id() ?? Auth::id();

        try {
            return DB::transaction(function () use ($merchantId, $data, $normalizedKey, $targetStatus, $userId) {
                // 1. Cek Idempotency-Key terlebih dahulu di dalam transaksi.
                if ($normalizedKey !== null) {
                    $existing = SalesTransaction::query()
                        ->where('merchant_id', $merchantId)
                        ->where('idempotency_key', $normalizedKey)
                        ->first();

                    if ($existing) {
                        return $this->loadFullRelations($existing);
                    }
                }

            // 2. Validasi cabang, gudang utama, dan customer (jika diisi).
            $branchInfo = $this->catalogService->resolveBranchWarehouse((int) $data['branch_id']);
            $branchId = $branchInfo['branch_id'];
            $warehouseId = $branchInfo['warehouse_id'];

            $customerId = $this->validateCustomer($merchantId, $data['customer_id'] ?? null);

                // 3. Jika checkout (completed), wajib memiliki shift kasir yang sedang terbuka (shared lock cegah race dengan close shift).
                $shift = null;
                if ($targetStatus === SalesTransactionStatus::Completed) {
                    $shift = $this->resolveOpenShift($merchantId, $branchId, $userId, $data['cashier_shift_id'] ?? null, lockShare: true);
                } elseif (! empty($data['cashier_shift_id'])) {
                    $shift = $this->resolveOpenShift($merchantId, $branchId, $userId, (int) $data['cashier_shift_id']);
                }

            // 4. Resolusi konfigurasi cabang (Order Type, Service Charge, Queue Number, Dining Table) & kalkulasi item.
            $branchPosConfig = $this->configResolver->resolveForBranch($branchId);
            $defaultOrderTypeValue = ! empty($branchPosConfig['capabilities']['order_type'])
                ? (string) (($branchPosConfig['enabled_order_types'][0] ?? null) ?: SalesOrderType::DineIn->value)
                : SalesOrderType::Retail->value;

            $orderType = SalesOrderType::tryFrom((string) ($data['order_type'] ?? ''))
                ?? SalesOrderType::tryFrom($defaultOrderTypeValue)
                ?? SalesOrderType::Retail;

            $diningTableId = null;
            $guestCount = ! empty($data['guest_count']) ? max(1, (int) $data['guest_count']) : null;
            if (
                $orderType === SalesOrderType::DineIn
                && ! empty($data['dining_table_id'])
                && Schema::hasTable('dining_tables')
            ) {
                $candidateTableId = (int) $data['dining_table_id'];
                $validTable = DB::table('dining_tables')
                    ->where('id', $candidateTableId)
                    ->where('merchant_id', $merchantId)
                    ->where('branch_id', $branchId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $validTable) {
                    throw ValidationException::withMessages([
                        'dining_table_id' => 'Meja tidak ditemukan atau tidak aktif pada cabang ini.',
                    ]);
                }
                $diningTableId = $candidateTableId;
            }

            $serviceChargeRate = (! empty($branchPosConfig['capabilities']['service_charge']) && $orderType->appliesServiceCharge())
                ? round(max(0.0, (float) ($branchPosConfig['service_charge_rate'] ?? 0)), 2)
                : 0.0;

            $priceListId = ! empty($data['price_list_id']) ? (int) $data['price_list_id'] : null;
            if ($priceListId !== null && Schema::hasTable('price_lists')) {
                $plValid = DB::table('price_lists')
                    ->where('id', $priceListId)
                    ->where('merchant_id', $merchantId)
                    ->where('is_active', true)
                    ->exists();
                if (! $plValid) {
                    throw ValidationException::withMessages([
                        'price_list_id' => 'Daftar harga tidak valid atau tidak aktif.',
                    ]);
                }
            }

            $salesChannel = isset($data['sales_channel']) && trim((string) $data['sales_channel']) !== ''
                ? trim((string) $data['sales_channel'])
                : null;
            $channelOrderRef = isset($data['channel_order_ref']) && trim((string) $data['channel_order_ref']) !== ''
                ? trim((string) $data['channel_order_ref'])
                : null;

            $computed = $this->computeLinesAndTotals(
                merchantId: $merchantId,
                branchId: $branchId,
                rawItems: $data['items'] ?? [],
                orderDiscountType: (string) ($data['discount_type'] ?? SalesDiscountType::None->value),
                orderDiscountValue: (float) ($data['discount_value'] ?? 0),
                priceListId: $priceListId,
                serviceChargeRate: $serviceChargeRate,
                checkSoldOut: true,
            );

            // 5. Validasi pembayaran jika completed.
            $paymentSummary = [
                'paid_amount' => 0.0,
                'change_amount' => 0.0,
                'payments' => [],
            ];

            if ($targetStatus === SalesTransactionStatus::Completed) {
                $paymentSummary = $this->validateAndComputePayments(
                    payments: $data['payments'] ?? [],
                    grandTotal: $computed['grand_total'],
                );
            }

            // 6. Buat header transaksi.
            $transactionNumber = $this->numberService->nextTransactionNumber();
            $now = now();

            $hasFnbColumns = Schema::hasColumn('sales_transactions', 'order_type');
            $queueNumber = null;
            if ($hasFnbColumns && ($orderType->isFnb() || ! empty($branchPosConfig['capabilities']['order_type']))) {
                $queueNumber = $this->numberService->nextQueueNumber($branchId);
            }

            $txPayload = [
                'merchant_id' => $merchantId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'cashier_shift_id' => $shift?->id,
                'customer_id' => $customerId,
                'transaction_number' => $transactionNumber,
                'idempotency_key' => $normalizedKey,
                'status' => $targetStatus->value,
                'subtotal' => $computed['subtotal'],
                'discount_type' => $computed['order_discount_type'],
                'discount_value' => $computed['order_discount_value'],
                'discount_amount' => $computed['total_discount_amount'],
                'tax_amount' => $computed['tax_amount'],
                'grand_total' => $computed['grand_total'],
                'paid_amount' => $paymentSummary['paid_amount'],
                'change_amount' => $paymentSummary['change_amount'],
                'total_cogs' => 0,
                'hold_label' => $data['hold_label'] ?? null,
                'notes' => $data['notes'] ?? null,
                'sold_at' => $targetStatus === SalesTransactionStatus::Completed ? $now : null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ];

            if ($hasFnbColumns) {
                $txPayload['order_type'] = $orderType->value;
                $txPayload['queue_number'] = $queueNumber;
                $txPayload['service_charge_rate'] = $computed['service_charge_rate'];
                $txPayload['service_charge_amount'] = $computed['service_charge_amount'];
                if (Schema::hasColumn('sales_transactions', 'dining_table_id')) {
                    $txPayload['dining_table_id'] = $diningTableId;
                    $txPayload['guest_count'] = $guestCount;
                }
                if (Schema::hasColumn('sales_transactions', 'price_list_id')) {
                    $txPayload['price_list_id'] = $priceListId;
                    $txPayload['sales_channel'] = $salesChannel;
                    if (Schema::hasColumn('sales_transactions', 'channel_order_ref')) {
                        $txPayload['channel_order_ref'] = $channelOrderRef;
                    }
                }
            }

            $transaction = SalesTransaction::query()->create($txPayload);

            // 7. Simpan item & lakukan mutasi stok (DEC-07: urutkan deterministik per product_variant_id ASC).
            $totalCogs = $this->persistItemsAndDeductStock(
                merchantId: $merchantId,
                transaction: $transaction,
                lines: $computed['lines'],
                deductStock: $targetStatus === SalesTransactionStatus::Completed,
                soldAt: $now,
            );

            if ($targetStatus === SalesTransactionStatus::Completed) {
                $transaction->update(['total_cogs' => $totalCogs]);

                if (! empty($paymentSummary['payments'])) {
                    $paymentRows = array_map(fn (array $payment) => [
                        'merchant_id' => $merchantId,
                        'sales_transaction_id' => $transaction->id,
                        'payment_method' => $payment['payment_method'],
                        'amount' => $payment['amount'],
                        'reference_number' => $payment['reference_number'],
                        'paid_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $paymentSummary['payments']);

                    SalesPayment::query()->insert($paymentRows);
                }

                // Pay-First Dine-in dengan Meja: tandai meja 'occupied' (Terisi - Lunas, active_transaction_id tetap null)
                // agar tamu bisa Pindah Meja (sinkron KDS) atau Pesan Tambahan di meja yang sama.
                if ($diningTableId !== null && Schema::hasTable('dining_tables')) {
                    DB::table('dining_tables')
                        ->where('id', $diningTableId)
                        ->where('merchant_id', $merchantId)
                        ->whereNull('active_transaction_id')
                        ->update([
                            'status' => 'occupied',
                            'guest_count' => $guestCount ?? 1,
                            'updated_at' => $now,
                        ]);
                }
            }

            $loaded = $this->loadFullRelations($transaction);
            if ($targetStatus === SalesTransactionStatus::Completed && $orderType->isFnb()) {
                app(KitchenDisplayService::class)->dispatchForTransaction($loaded, incremental: true);
            }

            return $loaded;
        });
        } catch (UniqueConstraintViolationException $e) {
            if ($normalizedKey !== null) {
                $existing = SalesTransaction::query()
                    ->where('merchant_id', $merchantId)
                    ->where('idempotency_key', $normalizedKey)
                    ->first();

                if ($existing) {
                    return $this->loadFullRelations($existing);
                }
            }

            throw $e;
        }
    }

    /**
     * Perbarui item & total pada transaksi berstatus 'held' (misal Open Bill meja yang ditambah item).
     */
    public function updateHeld(int $id, array $data): SalesTransaction
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $userId = Auth::guard('merchant')->id() ?? Auth::id();

        return DB::transaction(function () use ($merchantId, $id, $data, $userId) {
            $transaction = SalesTransaction::query()
                ->where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($transaction->status !== SalesTransactionStatus::Held) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya transaksi berstatus held / open bill yang dapat diperbarui.',
                ]);
            }

            $branchId = (int) $transaction->branch_id;
            $customerId = array_key_exists('customer_id', $data)
                ? $this->validateCustomer($merchantId, $data['customer_id'])
                : $transaction->customer_id;

            $branchPosConfig = $this->configResolver->resolveForBranch($branchId);
            $orderType = SalesOrderType::tryFrom((string) ($data['order_type'] ?? ''))
                ?? ($transaction->order_type instanceof SalesOrderType ? $transaction->order_type : SalesOrderType::Retail);
            $serviceChargeRate = (! empty($branchPosConfig['capabilities']['service_charge']) && $orderType->appliesServiceCharge())
                ? round(max(0.0, (float) ($branchPosConfig['service_charge_rate'] ?? 0)), 2)
                : 0.0;

            $computed = $this->computeLinesAndTotals(
                merchantId: $merchantId,
                branchId: $branchId,
                rawItems: $data['items'] ?? [],
                orderDiscountType: (string) ($data['discount_type'] ?? $transaction->discount_type->value),
                orderDiscountValue: (float) ($data['discount_value'] ?? $transaction->discount_value),
                priceListId: ! empty($data['price_list_id']) ? (int) $data['price_list_id'] : null,
                serviceChargeRate: $serviceChargeRate,
            );

            SalesTransactionItem::query()
                ->where('merchant_id', $merchantId)
                ->where('sales_transaction_id', $transaction->id)
                ->delete();

            $this->persistItemsAndDeductStock(
                merchantId: $merchantId,
                transaction: $transaction,
                lines: $computed['lines'],
                deductStock: false,
                soldAt: now(),
            );

            $updatePayload = [
                'customer_id' => $customerId,
                'subtotal' => $computed['subtotal'],
                'discount_type' => $computed['order_discount_type'],
                'discount_value' => $computed['order_discount_value'],
                'discount_amount' => $computed['total_discount_amount'],
                'tax_amount' => $computed['tax_amount'],
                'grand_total' => $computed['grand_total'],
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $transaction->notes,
                'updated_by' => $userId,
            ];

            if (Schema::hasColumn('sales_transactions', 'order_type')) {
                $updatePayload['order_type'] = $orderType->value;
                $updatePayload['service_charge_rate'] = $computed['service_charge_rate'];
                $updatePayload['service_charge_amount'] = $computed['service_charge_amount'];
                if (Schema::hasColumn('sales_transactions', 'dining_table_id')) {
                    if (array_key_exists('dining_table_id', $data)) {
                        $updatePayload['dining_table_id'] = ! empty($data['dining_table_id']) ? (int) $data['dining_table_id'] : null;
                    }
                    if (array_key_exists('guest_count', $data)) {
                        $updatePayload['guest_count'] = ! empty($data['guest_count']) ? (int) $data['guest_count'] : null;
                    }
                }
            }

            $transaction->update($updatePayload);

            return $this->loadFullRelations($transaction);
        });
    }

    /**
     * Selesaikan (checkout) transaksi yang sebelumnya berstatus 'held'.
     */
    public function completeHeld(int $id, array $data, ?string $idempotencyKey = null): SalesTransaction
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $normalizedKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? substr(trim($idempotencyKey), 0, 100)
            : null;

        $userId = Auth::guard('merchant')->id() ?? Auth::id();

        try {
            return DB::transaction(function () use ($merchantId, $id, $data, $normalizedKey, $userId) {
            if ($normalizedKey !== null) {
                $existing = SalesTransaction::query()
                    ->where('merchant_id', $merchantId)
                    ->where('idempotency_key', $normalizedKey)
                    ->first();

                if ($existing) {
                    return $this->loadFullRelations($existing);
                }
            }

            $itemRelation = Schema::hasTable('sales_transaction_item_modifiers') ? 'items.modifiers' : 'items';

            $transaction = SalesTransaction::query()
                ->where('merchant_id', $merchantId)
                ->with($itemRelation)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($transaction->status !== SalesTransactionStatus::Held) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya transaksi berstatus ditahan (held) yang dapat dilanjutkan ke pembayaran.',
                ]);
            }

            $branchId = (int) $transaction->branch_id;
            $shift = $this->resolveOpenShift($merchantId, $branchId, $userId, $data['cashier_shift_id'] ?? $transaction->cashier_shift_id, lockShare: true);

            $customerId = array_key_exists('customer_id', $data)
                ? $this->validateCustomer($merchantId, $data['customer_id'])
                : $transaction->customer_id;

            // Jika item dikirim ulang pada payload, hitung ulang; jika tidak, pakai item yang sudah tersimpan.
            $rawItems = ! empty($data['items'])
                ? $data['items']
                : $transaction->items->map(fn (SalesTransactionItem $item) => [
                    'product_variant_unit_id' => (int) $item->product_variant_unit_id,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount_type' => $item->discount_type->value,
                    'discount_value' => (float) $item->discount_value,
                    'notes' => $item->notes,
                    'modifiers' => $item->relationLoaded('modifiers')
                        ? $item->modifiers
                            ->filter(fn ($m) => $m->modifier_option_id !== null)
                            ->map(fn ($m) => [
                                'modifier_option_id' => (int) $m->modifier_option_id,
                                'quantity' => (int) $m->quantity,
                            ])->values()->all()
                        : [],
                ])->all();

            $branchPosConfig = $this->configResolver->resolveForBranch($branchId);
            $orderType = SalesOrderType::tryFrom((string) ($data['order_type'] ?? ''))
                ?? ($transaction->order_type instanceof SalesOrderType ? $transaction->order_type : SalesOrderType::Retail);
            $serviceChargeRate = (! empty($branchPosConfig['capabilities']['service_charge']) && $orderType->appliesServiceCharge())
                ? round(max(0.0, (float) ($branchPosConfig['service_charge_rate'] ?? 0)), 2)
                : 0.0;

            $computed = $this->computeLinesAndTotals(
                merchantId: $merchantId,
                branchId: $branchId,
                rawItems: $rawItems,
                orderDiscountType: (string) ($data['discount_type'] ?? $transaction->discount_type->value),
                orderDiscountValue: (float) ($data['discount_value'] ?? $transaction->discount_value),
                priceListId: ! empty($data['price_list_id']) ? (int) $data['price_list_id'] : null,
                serviceChargeRate: $serviceChargeRate,
            );

            $paymentSummary = $this->validateAndComputePayments(
                payments: $data['payments'] ?? [],
                grandTotal: $computed['grand_total'],
            );

            $now = now();

            // Hapus item lama sebelum menyimpan item final dan memotong stok.
            SalesTransactionItem::query()
                ->where('merchant_id', $merchantId)
                ->where('sales_transaction_id', $transaction->id)
                ->delete();

            $totalCogs = $this->persistItemsAndDeductStock(
                merchantId: $merchantId,
                transaction: $transaction,
                lines: $computed['lines'],
                deductStock: true,
                soldAt: $now,
            );

            $updatePayload = [
                'cashier_shift_id' => $shift->id,
                'customer_id' => $customerId,
                'idempotency_key' => $normalizedKey ?? $transaction->idempotency_key,
                'status' => SalesTransactionStatus::Completed->value,
                'subtotal' => $computed['subtotal'],
                'discount_type' => $computed['order_discount_type'],
                'discount_value' => $computed['order_discount_value'],
                'discount_amount' => $computed['total_discount_amount'],
                'tax_amount' => $computed['tax_amount'],
                'grand_total' => $computed['grand_total'],
                'paid_amount' => $paymentSummary['paid_amount'],
                'change_amount' => $paymentSummary['change_amount'],
                'total_cogs' => $totalCogs,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $transaction->notes,
                'sold_at' => $now,
                'updated_by' => $userId,
            ];

            if (Schema::hasColumn('sales_transactions', 'order_type')) {
                $updatePayload['order_type'] = $orderType->value;
                $updatePayload['service_charge_rate'] = $computed['service_charge_rate'];
                $updatePayload['service_charge_amount'] = $computed['service_charge_amount'];
                if ($transaction->queue_number === null && ($orderType->isFnb() || ! empty($branchPosConfig['capabilities']['order_type']))) {
                    $updatePayload['queue_number'] = $this->numberService->nextQueueNumber($branchId);
                }
            }

            $transaction->update($updatePayload);

            if (! empty($paymentSummary['payments'])) {
                $paymentRows = array_map(fn (array $payment) => [
                    'merchant_id' => $merchantId,
                    'sales_transaction_id' => $transaction->id,
                    'payment_method' => $payment['payment_method'],
                    'amount' => $payment['amount'],
                    'reference_number' => $payment['reference_number'],
                    'paid_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $paymentSummary['payments']);

                SalesPayment::query()->insert($paymentRows);
            }

            // Bebaskan meja secara otomatis jika transaksi ini adalah Open Bill milik suatu meja.
            if (Schema::hasTable('dining_tables')) {
                $nextTableStatus = ! empty($branchPosConfig['auto_cleaning_after_payment']) ? 'cleaning' : 'available';
                DB::table('dining_tables')
                    ->where('merchant_id', $merchantId)
                    ->where('active_transaction_id', $transaction->id)
                    ->update([
                        'status' => $nextTableStatus,
                        'active_transaction_id' => null,
                        'guest_count' => null,
                        'updated_at' => $now,
                    ]);
            }

            $loaded = $this->loadFullRelations($transaction);
            if ($orderType->isFnb()) {
                app(KitchenDisplayService::class)->dispatchForTransaction($loaded, incremental: true);
            }

            return $loaded;
        });
        } catch (UniqueConstraintViolationException $e) {
            if ($normalizedKey !== null) {
                $existing = SalesTransaction::query()
                    ->where('merchant_id', $merchantId)
                    ->where('idempotency_key', $normalizedKey)
                    ->first();

                if ($existing) {
                    return $this->loadFullRelations($existing);
                }
            }

            throw $e;
        }
    }

    /**
     * Hapus transaksi berstatus 'held' (belum pernah memotong stok).
     */
    public function deleteHeld(int $id): void
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        DB::transaction(function () use ($merchantId, $id) {
            $transaction = SalesTransaction::query()
                ->where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($transaction->status !== SalesTransactionStatus::Held) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya bill tertahan (held) yang dapat dihapus. Gunakan Void untuk transaksi selesai.',
                ]);
            }

            if (Schema::hasTable('dining_tables')) {
                DB::table('dining_tables')
                    ->where('merchant_id', $merchantId)
                    ->where('active_transaction_id', $transaction->id)
                    ->update([
                        'status' => 'available',
                        'active_transaction_id' => null,
                        'guest_count' => null,
                        'updated_at' => now(),
                    ]);
            }

            $transaction->delete();
        });
    }

    /**
     * Batalkan (Void) transaksi 'completed' dan kembalikan stok dengan HPP aslinya.
     */
    public function void(int $id, string $reason): SalesTransaction
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $userId = Auth::guard('merchant')->id() ?? Auth::id();

        return DB::transaction(function () use ($merchantId, $id, $reason, $userId) {
            $transaction = SalesTransaction::query()
                ->where('merchant_id', $merchantId)
                ->with(['items.product' => fn ($q) => $q->withTrashed()->select('id', 'track_stock')])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($transaction->status !== SalesTransactionStatus::Completed) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya transaksi berstatus selesai (completed) yang dapat dibatalkan (void).',
                ]);
            }

            if (Schema::hasTable('sales_returns')) {
                $hasActiveReturns = DB::table('sales_returns')
                    ->where('merchant_id', $merchantId)
                    ->where('sales_transaction_id', $transaction->id)
                    ->where('status', 'completed')
                    ->whereNull('deleted_at')
                    ->exists();

                if ($hasActiveReturns) {
                    throw ValidationException::withMessages([
                        'status' => 'Transaksi ini sudah memiliki retur penjualan aktif. Batalkan dokumen retur terlebih dahulu sebelum melakukan void transaksi.',
                    ]);
                }
            }

            $now = now();

            // DEC-07: Urutkan deterministik sebelum memutasi warehouse_stocks untuk mencegah deadlock.
            $sortedItems = $transaction->items
                ->sortBy([
                    ['product_variant_id', 'asc'],
                    ['id', 'asc'],
                ])
                ->values();

            foreach ($sortedItems as $item) {
                $trackStock = $item->product ? (bool) $item->product->track_stock : true;
                if (! $trackStock) {
                    continue;
                }

                $this->stockService->recordMutation(
                    warehouseId: (int) $transaction->warehouse_id,
                    productVariantId: (int) $item->product_variant_id,
                    baseQuantity: (float) $item->base_quantity,
                    mutationType: MutationType::SalesVoid->value,
                    incomingUnitCost: (float) $item->unit_cost,
                    referenceType: 'sales_transaction',
                    referenceId: (int) $transaction->id,
                    referenceNumber: $transaction->transaction_number,
                    notes: 'Void penjualan: '.trim($reason),
                    mutatedAt: $now,
                );
            }

            $transaction->update([
                'status' => SalesTransactionStatus::Voided->value,
                'voided_at' => $now,
                'voided_by' => $userId,
                'void_reason' => trim($reason),
                'updated_by' => $userId,
            ]);

            app(KitchenDisplayService::class)->voidTicketsForTransaction($transaction->id);

            return $this->loadFullRelations($transaction);
        });
    }

    private function validateCustomer(int $merchantId, mixed $customerId): ?int
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $id = (int) $customerId;
        $exists = DB::table('customers')
            ->where('id', $id)
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'customer_id' => 'Pelanggan tidak ditemukan.',
            ]);
        }

        return $id;
    }

    private function resolveOpenShift(int $merchantId, int $branchId, ?int $userId, mixed $shiftId = null, bool $lockShare = false): CashierShift
    {
        $query = CashierShift::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->where('status', CashierShiftStatus::Open->value);

        if ($shiftId !== null && $shiftId !== '') {
            $query->where('id', (int) $shiftId);
        } elseif ($userId) {
            $query->where('user_id', $userId);
        }

        if ($lockShare) {
            $query->sharedLock();
        }

        $shift = $query->orderByDesc('opened_at')->first();

        if (! $shift) {
            throw ValidationException::withMessages([
                'cashier_shift_id' => 'Belum ada shift kasir yang aktif pada cabang ini. Silakan buka shift terlebih dahulu.',
            ]);
        }

        $authUser = Auth::guard('merchant')->user();
        if ($authUser && (int) $shift->user_id !== (int) $authUser->getAuthIdentifier()) {
            $canManageAny = (method_exists($authUser, 'hasRole') && $authUser->hasRole('owner'))
                || (method_exists($authUser, 'can') && $authUser->can('sales.shift.manage'));

            abort_unless($canManageAny, 403, 'Anda tidak memiliki izin untuk memposting transaksi ke shift kasir milik pengguna lain.');
        }

        return $shift;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array<string, mixed>
     */
    private function computeLinesAndTotals(
        int $merchantId,
        int $branchId,
        array $rawItems,
        string $orderDiscountType,
        float $orderDiscountValue,
        ?int $priceListId,
        float $serviceChargeRate = 0.0,
        bool $checkSoldOut = false,
    ): array {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => 'Minimal harus ada satu item produk dalam transaksi.',
            ]);
        }

        $unitIds = collect($rawItems)->pluck('product_variant_unit_id')->map(fn ($id) => (int) $id)->unique()->all();

        $variantUnits = ProductVariantUnit::query()
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereIn('id', $unitIds)
            ->with([
                'unit:id,name,code',
                'variant:id,product_id,name,sku,is_active',
                'variant.product:id,name,track_stock,can_be_sold,is_taxable,tax_rate,is_active',
            ])
            ->get()
            ->keyBy('id');

        $variantIds = $variantUnits
            ->map(fn (ProductVariantUnit $vu) => (int) $vu->product_variant_id)
            ->unique()
            ->values()
            ->all();

        if ($checkSoldOut && ! empty($variantIds) && Schema::hasTable('branch_sold_out_items')) {
            $today = now()->toDateString();
            $soldOutVariantIds = DB::table('branch_sold_out_items')
                ->where('merchant_id', $merchantId)
                ->where('branch_id', $branchId)
                ->whereIn('product_variant_id', $variantIds)
                ->where(function ($q) use ($today) {
                    $q->whereNull('sold_out_date')
                        ->orWhere('sold_out_date', '>=', $today);
                })
                ->pluck('product_variant_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! empty($soldOutVariantIds)) {
                $firstBlocked = $variantUnits->first(
                    fn (ProductVariantUnit $vu) => in_array((int) $vu->product_variant_id, $soldOutVariantIds, true),
                );
                $blockedName = $firstBlocked?->variant?->product?->name ?? 'Produk';
                throw ValidationException::withMessages([
                    'items' => "Menu \"{$blockedName}\" sedang ditandai Habis Hari Ini (86) pada cabang ini.",
                ]);
            }
        }

        $assignedVariantIds = empty($variantIds)
            ? []
            : DB::table('branch_active_products')
                ->where('merchant_id', $merchantId)
                ->where('branch_id', $branchId)
                ->whereIn('product_variant_id', $variantIds)
                ->pluck('product_variant_id')
                ->map(fn ($id) => (int) $id)
                ->flip()
                ->all();

        $validatedRows = [];
        $priceLines = [];

        foreach ($rawItems as $index => $row) {
            $unitId = (int) ($row['product_variant_unit_id'] ?? 0);
            /** @var ProductVariantUnit|null $vu */
            $vu = $variantUnits->get($unitId);

            if (! $vu || ! $vu->variant || ! $vu->variant->is_active || ! $vu->variant->product || ! $vu->variant->product->is_active) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_unit_id" => 'Produk atau satuan jual tidak ditemukan atau tidak aktif.',
                ]);
            }

            if (! $vu->variant->product->can_be_sold) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_unit_id" => "Produk '{$vu->variant->product->name}' tidak diaktifkan untuk dijual.",
                ]);
            }

            if (! array_key_exists((int) $vu->product_variant_id, $assignedVariantIds)) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_unit_id" => "Produk '{$vu->variant->product->name}' tidak tersedia untuk dijual pada cabang ini.",
                ]);
            }

            $quantity = round((float) ($row['quantity'] ?? 0), 4);
            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => 'Kuantitas item harus lebih dari nol.',
                ]);
            }

            $validatedRows[$index] = [
                'row' => $row,
                'vu' => $vu,
                'quantity' => $quantity,
            ];
            $priceLines[$index] = [
                'unit' => $vu,
                'quantity' => $quantity,
            ];
        }

        // Batch-resolve seluruh harga item dalam 1 query product_prices (menghilangkan N+1 pada checkout).
        $resolvedPrices = $this->priceResolver->resolveManyForLines(
            lines: $priceLines,
            priceListId: $priceListId,
            branchId: $branchId,
            skipScopeValidation: $priceListId === null,
        );

        // Batch-load modifier options & product modifier group rules (ZERO N+1 queries).
        $productIds = collect($validatedRows)
            ->map(fn (array $entry) => (int) $entry['vu']->variant->product_id)
            ->unique()
            ->values()
            ->all();

        $allModifierOptionIds = collect($validatedRows)
            ->flatMap(fn (array $entry) => collect($entry['row']['modifiers'] ?? [])->pluck('modifier_option_id'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $modifierOptionsById = collect();
        $productModifierRules = [];

        if (Schema::hasTable('modifier_options') && Schema::hasTable('modifier_groups') && Schema::hasTable('product_modifier_groups')) {
            if (! empty($allModifierOptionIds)) {
                $modifierOptionsById = ModifierOption::query()
                    ->where('merchant_id', $merchantId)
                    ->where('is_active', true)
                    ->whereIn('id', $allModifierOptionIds)
                    ->with(['group:id,name,selection_type,is_required,min_select,max_select,is_active'])
                    ->get()
                    ->keyBy('id');
            }

            if (! empty($productIds)) {
                $groupLinks = DB::table('product_modifier_groups')
                    ->join('modifier_groups', 'modifier_groups.id', '=', 'product_modifier_groups.modifier_group_id')
                    ->where('product_modifier_groups.merchant_id', $merchantId)
                    ->whereIn('product_modifier_groups.product_id', $productIds)
                    ->where('modifier_groups.merchant_id', $merchantId)
                    ->where('modifier_groups.is_active', true)
                    ->whereNull('modifier_groups.deleted_at')
                    ->get([
                        'product_modifier_groups.product_id',
                        'modifier_groups.id as group_id',
                        'modifier_groups.name',
                        'modifier_groups.selection_type',
                        'modifier_groups.is_required',
                        'modifier_groups.min_select',
                        'modifier_groups.max_select',
                    ]);

                foreach ($groupLinks as $link) {
                    $productModifierRules[(int) $link->product_id][(int) $link->group_id] = $link;
                }
            }
        }

        $lines = [];
        $subtotalSum = 0.0;
        $itemDiscountSum = 0.0;
        $taxSum = 0.0;
        $maxTaxRate = 0.0;

        foreach ($validatedRows as $index => $entry) {
            $row = $entry['row'];
            /** @var ProductVariantUnit $vu */
            $vu = $entry['vu'];
            $quantity = $entry['quantity'];

            $conversion = (float) $vu->conversion_value;
            $baseQuantity = round($quantity * $conversion, 4);

            // Selalu resolve harga jual murni dari server (PriceResolverService) agar tidak dapat dimanipulasi dari client.
            $resolved = $resolvedPrices[$index] ?? ['price' => (float) $vu->default_price];
            $unitPrice = round((float) $resolved['price'], 2);

            $product = $vu->variant->product;
            $productId = (int) $product->id;
            $allowedGroups = $productModifierRules[$productId] ?? [];
            $selectedModifiers = [];
            $selectedCountByGroup = [];
            $modifiersUnitTotal = 0.0;

            foreach (($row['modifiers'] ?? []) as $modIndex => $modRow) {
                $optionId = (int) ($modRow['modifier_option_id'] ?? 0);
                /** @var ModifierOption|null $option */
                $option = $modifierOptionsById->get($optionId);
                if (! $option || ! $option->group || ! $option->group->is_active) {
                    throw ValidationException::withMessages([
                        "items.{$index}.modifiers.{$modIndex}.modifier_option_id" => 'Opsi modifier tidak ditemukan atau tidak aktif.',
                    ]);
                }

                $groupId = (int) $option->modifier_group_id;
                if (! empty($allowedGroups) && ! array_key_exists($groupId, $allowedGroups)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.modifiers.{$modIndex}.modifier_option_id" => "Modifier '{$option->name}' tidak tersedia untuk produk '{$product->name}'.",
                    ]);
                }

                $modQty = max(1, (int) ($modRow['quantity'] ?? 1));
                $priceAdj = round((float) $option->price_adjustment, 2);
                $modSub = round($priceAdj * $modQty, 2);
                $modifiersUnitTotal += $modSub;
                $selectedCountByGroup[$groupId] = ($selectedCountByGroup[$groupId] ?? 0) + 1;

                $selectedModifiers[] = [
                    'modifier_group_id' => $groupId,
                    'modifier_option_id' => $optionId,
                    'modifier_group_name' => (string) $option->group->name,
                    'modifier_option_name' => (string) $option->name,
                    'price_adjustment' => $priceAdj,
                    'quantity' => $modQty,
                    'subtotal' => $modSub,
                ];
            }

            foreach ($allowedGroups as $groupId => $groupRule) {
                $picked = (int) ($selectedCountByGroup[$groupId] ?? 0);
                $minReq = $groupRule->is_required ? max(1, (int) $groupRule->min_select) : (int) $groupRule->min_select;
                if ($picked < $minReq) {
                    throw ValidationException::withMessages([
                        "items.{$index}.modifiers" => "Grup modifier '{$groupRule->name}' wajib dipilih minimal {$minReq} opsi untuk produk '{$product->name}'.",
                    ]);
                }
                if ($groupRule->max_select !== null && $picked > (int) $groupRule->max_select) {
                    throw ValidationException::withMessages([
                        "items.{$index}.modifiers" => "Grup modifier '{$groupRule->name}' maksimal {$groupRule->max_select} opsi untuk produk '{$product->name}'.",
                    ]);
                }
            }

            $modifiersUnitTotal = round($modifiersUnitTotal, 2);
            $effectiveUnitPrice = round($unitPrice + $modifiersUnitTotal, 2);
            $lineSubtotal = round($quantity * $effectiveUnitPrice, 2);
            $itemDiscountType = (string) ($row['discount_type'] ?? SalesDiscountType::None->value);
            $itemDiscountValue = round(max(0.0, (float) ($row['discount_value'] ?? 0)), 2);
            if ($itemDiscountType === SalesDiscountType::Percentage->value) {
                $itemDiscountValue = min($itemDiscountValue, 100.0);
            }
            $itemDiscountAmount = $this->calculateDiscount($lineSubtotal, $itemDiscountType, $itemDiscountValue);

            $taxableBase = round(max(0.0, $lineSubtotal - $itemDiscountAmount), 2);
            $taxRate = $product->is_taxable ? round((float) $product->tax_rate, 2) : 0.0;
            if ($taxRate > $maxTaxRate) {
                $maxTaxRate = $taxRate;
            }
            $lineTaxAmount = $taxRate > 0 ? round($taxableBase * ($taxRate / 100), 2) : 0.0;
            $lineTotal = round($taxableBase + $lineTaxAmount, 2);

            $subtotalSum += $lineSubtotal;
            $itemDiscountSum += $itemDiscountAmount;
            $taxSum += $lineTaxAmount;

            $lines[] = [
                'product_id' => $productId,
                'product_variant_id' => (int) $vu->variant->id,
                'product_variant_unit_id' => (int) $vu->id,
                'product_name' => (string) $product->name,
                'variant_name' => $vu->variant->name,
                'sku' => $vu->variant->sku,
                'unit_name' => (string) ($vu->unit?->name ?? 'Pcs'),
                'unit_code' => $vu->unit?->code,
                'conversion_value' => $conversion,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'unit_price' => $unitPrice,
                'modifiers_total' => $modifiersUnitTotal,
                'modifiers' => $selectedModifiers,
                'discount_type' => $itemDiscountType,
                'discount_value' => $itemDiscountValue,
                'discount_amount' => $itemDiscountAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTaxAmount,
                'subtotal' => $lineSubtotal,
                'total' => $lineTotal,
                'track_stock' => (bool) $product->track_stock,
                'notes' => $row['notes'] ?? null,
                'course_number' => max(1, min(3, (int) ($row['course_number'] ?? 1))),
                'is_held_for_kitchen' => (bool) ($row['is_held_for_kitchen'] ?? false),
            ];
        }

        $subtotalSum = round($subtotalSum, 2);
        $itemDiscountSum = round($itemDiscountSum, 2);
        $netBeforeOrderDiscount = round(max(0.0, $subtotalSum - $itemDiscountSum), 2);
        $normalizedOrderDiscountValue = round(max(0.0, $orderDiscountValue), 2);
        if ($orderDiscountType === SalesDiscountType::Percentage->value) {
            $normalizedOrderDiscountValue = min($normalizedOrderDiscountValue, 100.0);
        }
        $orderDiscountAmount = $this->calculateDiscount($netBeforeOrderDiscount, $orderDiscountType, $normalizedOrderDiscountValue);
        $totalDiscountAmount = round($itemDiscountSum + $orderDiscountAmount, 2);

        $netAfterAllDiscounts = round(max(0.0, $subtotalSum - $totalDiscountAmount), 2);
        $normalizedServiceChargeRate = round(max(0.0, min(100.0, $serviceChargeRate)), 2);
        $serviceChargeAmount = $normalizedServiceChargeRate > 0
            ? round($netAfterAllDiscounts * ($normalizedServiceChargeRate / 100), 2)
            : 0.0;

        // Praktik F&B (PB1): jika ada service charge dan item kena pajak, pajak dikenakan pula atas service charge.
        $serviceChargeTax = ($serviceChargeAmount > 0 && $maxTaxRate > 0)
            ? round($serviceChargeAmount * ($maxTaxRate / 100), 2)
            : 0.0;

        $taxSum = round($taxSum + $serviceChargeTax, 2);
        $grandTotal = round(max(0.0, $subtotalSum - $totalDiscountAmount + $serviceChargeAmount + $taxSum), 2);

        return [
            'lines' => $lines,
            'subtotal' => $subtotalSum,
            'order_discount_type' => $orderDiscountType,
            'order_discount_value' => $normalizedOrderDiscountValue,
            'order_discount_amount' => $orderDiscountAmount,
            'total_discount_amount' => $totalDiscountAmount,
            'service_charge_rate' => $normalizedServiceChargeRate,
            'service_charge_amount' => $serviceChargeAmount,
            'tax_amount' => $taxSum,
            'grand_total' => $grandTotal,
        ];
    }

    private function calculateDiscount(float $baseAmount, string $type, float $value): float
    {
        if ($baseAmount <= 0 || $value <= 0) {
            return 0.0;
        }

        return match ($type) {
            SalesDiscountType::Percentage->value => round(min($baseAmount, $baseAmount * (min($value, 100.0) / 100)), 2),
            SalesDiscountType::Fixed->value => round(min($baseAmount, $value), 2),
            default => 0.0,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @return array{paid_amount:float,change_amount:float,payments:array<int, array<string, mixed>>}
     */
    private function validateAndComputePayments(array $payments, float $grandTotal): array
    {
        if ($grandTotal <= 0.0 && empty($payments)) {
            return [
                'paid_amount' => 0.0,
                'change_amount' => 0.0,
                'payments' => [],
            ];
        }

        if (empty($payments)) {
            throw ValidationException::withMessages([
                'payments' => 'Minimal satu metode pembayaran wajib diisi untuk menyelesaikan transaksi.',
            ]);
        }

        $normalized = [];
        $cashPaid = 0.0;
        $nonCashPaid = 0.0;

        foreach ($payments as $index => $row) {
            $method = SalesPaymentMethod::tryFrom((string) ($row['payment_method'] ?? ''));
            if (! $method) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payment_method" => 'Metode pembayaran tidak valid.',
                ]);
            }

            $amount = round((float) ($row['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    "payments.{$index}.amount" => 'Nominal pembayaran harus lebih dari nol.',
                ]);
            }

            if ($method->isCash()) {
                $cashPaid += $amount;
            } else {
                $nonCashPaid += $amount;
            }

            $normalized[] = [
                'payment_method' => $method->value,
                'amount' => $amount,
                'reference_number' => isset($row['reference_number']) && trim((string) $row['reference_number']) !== ''
                    ? trim((string) $row['reference_number'])
                    : null,
            ];
        }

        $paidAmount = round($cashPaid + $nonCashPaid, 2);

        if ($paidAmount < $grandTotal) {
            throw ValidationException::withMessages([
                'payments' => 'Total pembayaran kurang dari total tagihan.',
            ]);
        }

        if ($nonCashPaid > $grandTotal) {
            throw ValidationException::withMessages([
                'payments' => 'Total pembayaran non-tunai tidak boleh melebihi total tagihan.',
            ]);
        }

        $changeAmount = round(max(0.0, $paidAmount - $grandTotal), 2);

        return [
            'paid_amount' => $paidAmount,
            'change_amount' => $changeAmount,
            'payments' => $normalized,
        ];
    }

    /**
     * Simpan sales_transaction_items dan (jika deductStock = true) potong stok secara deterministik (DEC-07).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function persistItemsAndDeductStock(
        int $merchantId,
        SalesTransaction $transaction,
        array $lines,
        bool $deductStock,
        mixed $soldAt,
    ): float {
        // DEC-07: Urutkan deterministik berdasarkan product_variant_id ASC sebelum memutasi stok.
        usort($lines, fn (array $a, array $b) => [$a['product_variant_id'], $a['product_variant_unit_id']] <=> [$b['product_variant_id'], $b['product_variant_unit_id']]);

        $totalCogs = 0.0;
        $itemRows = [];
        $lineModifiersList = [];
        $hasAnyModifiers = false;
        $hasModifiersTotalColumn = Schema::hasColumn('sales_transaction_items', 'modifiers_total');
        $hasModifiersTable = Schema::hasTable('sales_transaction_item_modifiers');
        $now = now();

        foreach ($lines as $line) {
            $unitCost = 0.0;
            $lineCogs = 0.0;

            if ($deductStock && $line['track_stock']) {
                $mutation = $this->stockService->recordMutation(
                    warehouseId: (int) $transaction->warehouse_id,
                    productVariantId: (int) $line['product_variant_id'],
                    baseQuantity: -1 * (float) $line['base_quantity'],
                    mutationType: MutationType::SalesOut->value,
                    incomingUnitCost: null,
                    referenceType: 'sales_transaction',
                    referenceId: (int) $transaction->id,
                    referenceNumber: $transaction->transaction_number,
                    notes: 'Penjualan POS '.$transaction->transaction_number,
                    mutatedAt: $soldAt,
                );

                $unitCost = round((float) ($mutation->unit_cost ?? 0), 4);
                $lineCogs = round((float) $line['base_quantity'] * $unitCost, 2);
                $totalCogs += $lineCogs;
            }

            $itemRow = [
                'merchant_id' => $merchantId,
                'sales_transaction_id' => $transaction->id,
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
                'unit_cost' => $unitCost,
                'total_cogs' => $lineCogs,
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
                'discount_amount' => $line['discount_amount'],
                'tax_rate' => $line['tax_rate'],
                'tax_amount' => $line['tax_amount'],
                'subtotal' => $line['subtotal'],
                'total' => $line['total'],
                'notes' => $line['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($hasModifiersTotalColumn) {
                $itemRow['modifiers_total'] = $line['modifiers_total'] ?? 0;
            }
            if (Schema::hasColumn('sales_transaction_items', 'course_number')) {
                $itemRow['course_number'] = (int) ($line['course_number'] ?? 1);
                $itemRow['is_held_for_kitchen'] = (bool) ($line['is_held_for_kitchen'] ?? false);
            }

            $itemRows[] = $itemRow;
            $mods = $line['modifiers'] ?? [];
            $lineModifiersList[] = $mods;
            if (! empty($mods)) {
                $hasAnyModifiers = true;
            }
        }

        if (! empty($itemRows)) {
            if ($hasAnyModifiers && $hasModifiersTable) {
                $modifierInsertRows = [];
                foreach ($itemRows as $idx => $itemRow) {
                    $itemId = DB::table('sales_transaction_items')->insertGetId($itemRow);
                    foreach ($lineModifiersList[$idx] as $mod) {
                        $modifierInsertRows[] = [
                            'merchant_id' => $merchantId,
                            'sales_transaction_item_id' => $itemId,
                            'modifier_group_id' => $mod['modifier_group_id'],
                            'modifier_option_id' => $mod['modifier_option_id'],
                            'modifier_group_name' => $mod['modifier_group_name'],
                            'modifier_option_name' => $mod['modifier_option_name'],
                            'price_adjustment' => $mod['price_adjustment'],
                            'quantity' => $mod['quantity'],
                            'subtotal' => $mod['subtotal'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if (! empty($modifierInsertRows)) {
                    SalesTransactionItemModifier::query()->insert($modifierInsertRows);
                }
            } else {
                SalesTransactionItem::query()->insert($itemRows);
            }
        }

        return round($totalCogs, 2);
    }

    private function loadFullRelations(SalesTransaction $transaction): SalesTransaction
    {
        $itemRelation = Schema::hasTable('sales_transaction_item_modifiers') ? 'items.modifiers' : 'items';
        $relations = [
            'branch:id,name',
            'warehouse:id,name',
            'cashierShift:id,shift_number,status',
            'customer:id,code,name,phone',
            'creator:id,name',
            'voider:id,name',
            $itemRelation,
            'payments',
        ];
        if (Schema::hasTable('dining_tables')) {
            $relations[] = 'diningTable:id,code,name';
        }

        return $transaction->load($relations);
    }
}
