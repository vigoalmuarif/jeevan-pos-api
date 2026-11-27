<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Sales\Enums\SalesOrderType;
use Modules\Sales\Enums\SalesTransactionStatus;
use Modules\Sales\Models\DiningArea;
use Modules\Sales\Models\DiningTable;
use Modules\Sales\Models\SalesTransaction;
use Modules\Sales\Models\SalesTransactionItem;
use Modules\User\Models\User;

class TableManagementService
{
    public function __construct(
        private readonly SalesTransactionService $txService,
        private readonly KitchenDisplayService $kdsService,
        private readonly PosPinService $pinService,
    ) {}

    /**
     * Ambil daftar area & meja beserta ringkasan Open Bill aktif pada cabang (ZERO N+1 queries).
     *
     * @return array{areas: array<int, array<string, mixed>>, tables: array<int, array<string, mixed>>}
     */
    public function listByBranch(int $branchId): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $areas = DiningArea::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'sort_order', 'is_active'])
            ->map(fn (DiningArea $a) => [
                'id' => (int) $a->id,
                'branch_id' => (int) $a->branch_id,
                'name' => (string) $a->name,
                'sort_order' => (int) $a->sort_order,
                'is_active' => (bool) $a->is_active,
            ])
            ->values()
            ->all();

        $tableModels = DiningTable::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->with([
                'area:id,name',
                'activeTransaction' => fn ($q) => $q
                    ->select(['id', 'transaction_number', 'queue_number', 'status', 'grand_total', 'customer_id', 'created_at', 'updated_at'])
                    ->with(['customer:id,name'])
                    ->withCount('items'),
            ])
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        // Batch-load ringkasan status KDS aktif untuk meja yang terisi (1 query agregat, ZERO N+1)
        $occupiedCodes = $tableModels
            ->filter(fn (DiningTable $t) => $t->status === 'occupied')
            ->pluck('code')
            ->map(fn ($code) => (string) $code)
            ->values()
            ->all();
        $kdsSummaryByCode = $this->kdsService->batchTableKdsSummary($merchantId, $branchId, $occupiedCodes);

        $tables = $tableModels
            ->map(fn (DiningTable $t) => $this->formatTable($t, $kdsSummaryByCode[(string) $t->code] ?? null))
            ->values()
            ->all();

        return [
            'areas' => $areas,
            'tables' => $tables,
        ];
    }

    public function createArea(array $data): DiningArea
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');
        $this->assertBranchBelongsToMerchant($merchantId, (int) $data['branch_id']);

        return DiningArea::query()->create([
            'merchant_id' => $merchantId,
            'branch_id' => (int) $data['branch_id'],
            'name' => trim((string) $data['name']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    public function updateArea(int $id, array $data): DiningArea
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $area = DiningArea::query()
            ->where('merchant_id', $merchantId)
            ->findOrFail($id);

        $area->update([
            'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $area->name,
            'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $area->sort_order,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $area->is_active,
        ]);

        return $area->fresh();
    }

    public function deleteArea(int $id): void
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        DB::transaction(function () use ($merchantId, $id) {
            $area = DiningArea::query()
                ->where('merchant_id', $merchantId)
                ->findOrFail($id);

            DiningTable::query()
                ->where('merchant_id', $merchantId)
                ->where('dining_area_id', $area->id)
                ->update(['dining_area_id' => null]);

            $area->delete();
        });
    }

    public function createTable(array $data): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $branchId = (int) $data['branch_id'];
        $this->assertBranchBelongsToMerchant($merchantId, $branchId);

        $areaId = ! empty($data['dining_area_id']) ? (int) $data['dining_area_id'] : null;
        if ($areaId !== null) {
            $this->assertAreaBelongsToBranch($merchantId, $branchId, $areaId);
        }

        $code = strtoupper(trim((string) $data['code']));
        $duplicate = DiningTable::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->where('code', $code)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'code' => "Kode meja '{$code}' sudah digunakan pada cabang ini.",
            ]);
        }

        $table = DiningTable::query()->create([
            'merchant_id' => $merchantId,
            'branch_id' => $branchId,
            'dining_area_id' => $areaId,
            'code' => $code,
            'name' => trim((string) $data['name']),
            'capacity' => max(1, (int) ($data['capacity'] ?? 4)),
            'shape' => (string) ($data['shape'] ?? 'square'),
            'status' => 'available',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return $this->formatTable($table->load(['area:id,name']));
    }

    public function updateTable(int $id, array $data): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $table = DiningTable::query()
            ->where('merchant_id', $merchantId)
            ->findOrFail($id);

        $areaId = array_key_exists('dining_area_id', $data)
            ? (! empty($data['dining_area_id']) ? (int) $data['dining_area_id'] : null)
            : $table->dining_area_id;

        if ($areaId !== null) {
            $this->assertAreaBelongsToBranch($merchantId, (int) $table->branch_id, $areaId);
        }

        $code = array_key_exists('code', $data) ? strtoupper(trim((string) $data['code'])) : $table->code;
        if ($code !== $table->code) {
            $duplicate = DiningTable::query()
                ->where('merchant_id', $merchantId)
                ->where('branch_id', $table->branch_id)
                ->where('code', $code)
                ->where('id', '<>', $table->id)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'code' => "Kode meja '{$code}' sudah digunakan pada cabang ini.",
                ]);
            }
        }

        $table->update([
            'dining_area_id' => $areaId,
            'code' => $code,
            'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $table->name,
            'capacity' => array_key_exists('capacity', $data) ? max(1, (int) $data['capacity']) : $table->capacity,
            'shape' => array_key_exists('shape', $data) ? (string) $data['shape'] : $table->shape,
            'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $table->sort_order,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $table->is_active,
        ]);

        return $this->formatTable($table->fresh()->load(['area:id,name', 'activeTransaction.customer:id,name']));
    }

    public function updateStatus(int $id, string $status): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $table = DiningTable::query()
            ->where('merchant_id', $merchantId)
            ->findOrFail($id);

        if ($table->active_transaction_id !== null && $status === 'available') {
            throw ValidationException::withMessages([
                'status' => 'Meja masih memiliki tagihan aktif. Selesaikan atau batalkan bill terlebih dahulu.',
            ]);
        }

        $update = ['status' => $status];
        if (in_array($status, ['available', 'cleaning'], true) && $table->active_transaction_id === null) {
            $update['guest_count'] = null;
        }

        $table->update($update);

        return $this->formatTable($table->fresh()->load(['area:id,name', 'activeTransaction.customer:id,name']));
    }

    public function deleteTable(int $id): void
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $table = DiningTable::query()
            ->where('merchant_id', $merchantId)
            ->findOrFail($id);

        if ($table->active_transaction_id !== null) {
            throw ValidationException::withMessages([
                'table' => 'Tidak dapat menghapus meja yang sedang memiliki Open Bill aktif.',
            ]);
        }

        $table->delete();
    }

    /**
     * Buka Open Bill baru di meja atau perbarui item pada Open Bill yang sudah berjalan.
     */
    public function saveTableOrder(int $tableId, array $data): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        return DB::transaction(function () use ($merchantId, $tableId, $data) {
            $table = DiningTable::query()
                ->where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->findOrFail($tableId);

            if (! $table->is_active) {
                throw ValidationException::withMessages([
                    'dining_table_id' => 'Meja ini sedang tidak aktif.',
                ]);
            }

            $guestCount = isset($data['guest_count']) ? max(1, (int) $data['guest_count']) : ($table->guest_count ?? 1);
            $wasExistingOrder = $table->active_transaction_id !== null;

            $unitPriceByProduct = [];
            if ($wasExistingOrder) {
                $existingItems = DB::table('sales_transaction_items')
                    ->where('merchant_id', $merchantId)
                    ->where('sales_transaction_id', (int) $table->active_transaction_id)
                    ->get(['product_id', 'product_variant_unit_id', 'unit_price']);
                foreach ($existingItems as $ei) {
                    $unitPriceByProduct[(int) $ei->product_id] = [
                        'unit_price' => (float) $ei->unit_price,
                        'product_variant_unit_id' => (int) $ei->product_variant_unit_id,
                    ];
                }

                $tx = $this->txService->updateHeld((int) $table->active_transaction_id, [
                    ...$data,
                    'order_type' => SalesOrderType::DineIn->value,
                    'dining_table_id' => (int) $table->id,
                    'guest_count' => $guestCount,
                ]);
            } else {
                $tx = $this->txService->create([
                    ...$data,
                    'branch_id' => (int) $table->branch_id,
                    'status' => SalesTransactionStatus::Held->value,
                    'order_type' => SalesOrderType::DineIn->value,
                    'dining_table_id' => (int) $table->id,
                    'guest_count' => $guestCount,
                    'hold_label' => "Meja {$table->code}",
                ]);
            }

            $table->update([
                'status' => 'occupied',
                'active_transaction_id' => $tx->id,
                'guest_count' => $guestCount,
            ]);

            // Jika Open Bill lama diperbarui, sinkronkan dulu item yang dihapus/dikurangi ke KDS aktif.
            // Bila ada item yang sudah dikirim ke dapur dikurangi/dihapus, WAJIB ada alasan void + otorisasi Manager PIN + audit log.
            if ($wasExistingOrder) {
                $voidedDetails = $this->kdsService->syncRemovalsWithDetails($tx);
                if (! empty($voidedDetails)) {
                    $reasonCode = isset($data['void_reason_code']) ? trim((string) $data['void_reason_code']) : '';
                    $allowedReasons = ['wrong_input', 'guest_cancelled', 'out_of_stock', 'kitchen_issue', 'other'];
                    if (! in_array($reasonCode, $allowedReasons, true)) {
                        throw ValidationException::withMessages([
                            'void_reason_code' => 'Alasan pembatalan wajib dipilih untuk mengurangi atau menghapus item yang sudah dikirim ke dapur.',
                        ]);
                    }

                    $actingUser = Auth::guard('merchant')->user();
                    $approver = $this->pinService->authorizeSupervisorAction(
                        merchantId: $merchantId,
                        pin: isset($data['supervisor_pin']) ? (string) $data['supervisor_pin'] : null,
                        actingUser: $actingUser instanceof User ? $actingUser : null,
                    );

                    if (Schema::hasTable('sales_item_void_logs')) {
                        $now = now();
                        $reasonNote = isset($data['void_reason_note']) && trim((string) $data['void_reason_note']) !== ''
                            ? trim((string) $data['void_reason_note'])
                            : null;
                        $requestedBy = $actingUser instanceof User ? (int) $actingUser->id : null;
                        $authorizedBy = $approver?->id ? (int) $approver->id : $requestedBy;

                        $auditRows = array_map(function (array $entry) use (
                            $merchantId,
                            $table,
                            $tx,
                            $unitPriceByProduct,
                            $reasonCode,
                            $reasonNote,
                            $requestedBy,
                            $authorizedBy,
                            $now,
                        ) {
                            $meta = $unitPriceByProduct[$entry['product_id']] ?? ['unit_price' => 0.0, 'product_variant_unit_id' => null];
                            $unitPrice = (float) $meta['unit_price'];
                            $voidedQty = (float) $entry['voided_qty'];

                            return [
                                'merchant_id' => $merchantId,
                                'branch_id' => (int) $table->branch_id,
                                'sales_transaction_id' => (int) $tx->id,
                                'dining_table_id' => (int) $table->id,
                                'product_id' => (int) $entry['product_id'],
                                'product_variant_unit_id' => $meta['product_variant_unit_id'],
                                'item_name' => $entry['variant_name']
                                    ? "{$entry['item_name']} ({$entry['variant_name']})"
                                    : $entry['item_name'],
                                'voided_qty' => $voidedQty,
                                'unit_price' => $unitPrice,
                                'voided_amount' => round($unitPrice * $voidedQty, 2),
                                'reason_code' => $reasonCode,
                                'reason_note' => $reasonNote,
                                'requested_by' => $requestedBy,
                                'authorized_by' => $authorizedBy,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }, $voidedDetails);

                        DB::table('sales_item_void_logs')->insert($auditRows);
                    }
                }
            }

            // Otomatis kirim Incremental KOT untuk item baru ke KDS jika modul kitchen_display aktif.
            $this->kdsService->dispatchForTransaction($tx, incremental: true);
            $this->kdsService->attachSentKitchenQuantities($tx);

            return [
                'table' => $this->formatTable($table->fresh()->load(['area:id,name', 'activeTransaction.customer:id,name'])),
                'transaction' => $tx,
            ];
        });
    }

    /**
     * Course Firing (Hold & Fire): lepas status tahan (is_held_for_kitchen = false) untuk course tertentu
     * pada Open Bill aktif meja dan langsung kirim tiket KOT ke dapur (KDS).
     *
     * @return array{table: array<string, mixed>, transaction: SalesTransaction, fired_count: int}
     */
    public function fireCourse(int $tableId, ?int $courseNumber = null): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        return DB::transaction(function () use ($merchantId, $tableId, $courseNumber) {
            $table = DiningTable::query()
                ->where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->findOrFail($tableId);

            if (! $table->active_transaction_id) {
                throw ValidationException::withMessages([
                    'dining_table_id' => 'Meja ini tidak memiliki Open Bill aktif.',
                ]);
            }

            $tx = SalesTransaction::query()
                ->where('merchant_id', $merchantId)
                ->findOrFail((int) $table->active_transaction_id);

            $firedCount = 0;
            if (Schema::hasColumn('sales_transaction_items', 'is_held_for_kitchen')) {
                $q = DB::table('sales_transaction_items')
                    ->where('merchant_id', $merchantId)
                    ->where('sales_transaction_id', $tx->id)
                    ->where('is_held_for_kitchen', true);

                if ($courseNumber !== null) {
                    $q->where('course_number', $courseNumber);
                }

                $firedCount = $q->update([
                    'is_held_for_kitchen' => false,
                    'updated_at' => now(),
                ]);
            }

            $tx->unsetRelation('items');
            $tx->load(['items.modifiers', 'diningTable:id,code,name', 'customer:id,code,name,phone', 'payments', 'branch:id,name', 'warehouse:id,name', 'creator:id,name']);

            $this->kdsService->dispatchForTransaction($tx, incremental: true);
            $this->kdsService->attachSentKitchenQuantities($tx);

            return [
                'table' => $this->formatTable($table->fresh()->load(['area:id,name', 'activeTransaction.customer:id,name'])),
                'transaction' => $tx,
                'fired_count' => $firedCount,
            ];
        });
    }

    /**
     * Pindahkan Open Bill aktif dari satu meja ke meja lain yang tersedia (deterministic lock order).
     */
    public function moveTable(int $fromTableId, int $toTableId): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        if ($fromTableId === $toTableId) {
            throw ValidationException::withMessages([
                'target_table_id' => 'Meja tujuan tidak boleh sama dengan meja asal.',
            ]);
        }

        return DB::transaction(function () use ($merchantId, $fromTableId, $toTableId) {
            $ids = [$fromTableId, $toTableId];
            sort($ids);
            $locked = DiningTable::query()
                ->where('merchant_id', $merchantId)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var DiningTable|null $from */
            $from = $locked->get($fromTableId);
            /** @var DiningTable|null $to */
            $to = $locked->get($toTableId);

            if (! $from || ! $to || (int) $from->branch_id !== (int) $to->branch_id) {
                throw ValidationException::withMessages([
                    'target_table_id' => 'Meja asal dan meja tujuan harus berada pada cabang yang sama.',
                ]);
            }

            if ($from->status !== 'occupied' && $from->active_transaction_id === null) {
                throw ValidationException::withMessages([
                    'table' => 'Meja asal tidak sedang terisi.',
                ]);
            }

            if ($to->status !== 'available' || $to->active_transaction_id !== null || ! $to->is_active) {
                throw ValidationException::withMessages([
                    'target_table_id' => 'Meja tujuan tidak tersedia atau sedang terisi.',
                ]);
            }

            $txId = $from->active_transaction_id ? (int) $from->active_transaction_id : null;
            $guests = $from->guest_count;

            if ($txId !== null) {
                SalesTransaction::query()
                    ->where('merchant_id', $merchantId)
                    ->where('id', $txId)
                    ->update([
                        'dining_table_id' => $to->id,
                        'hold_label' => "Meja {$to->code}",
                    ]);
            }

            // Sinkronkan seluruh tiket KDS aktif (baik Pay-First maupun Pay-Later/Open Bill) ke meja baru
            $kdsUpdated = $this->kdsService->reassignTableForTickets(
                merchantId: $merchantId,
                branchId: (int) $from->branch_id,
                fromTableCode: (string) $from->code,
                toTableCode: (string) $to->code,
                toTableName: (string) $to->name,
                transactionId: $txId,
            );

            $to->update([
                'status' => 'occupied',
                'active_transaction_id' => $txId,
                'guest_count' => $guests,
            ]);

            $from->update([
                'status' => 'available',
                'active_transaction_id' => null,
                'guest_count' => null,
            ]);

            return [
                'from_table' => $this->formatTable($from->fresh()->load(['area:id,name'])),
                'to_table' => $this->formatTable($to->fresh()->load(['area:id,name', 'activeTransaction.customer:id,name'])),
                'kds_tickets_updated' => $kdsUpdated,
            ];
        });
    }

    /**
     * Gabungkan Open Bill dari meja sumber ke meja tujuan (semua item + modifier dipindahkan).
     */
    public function mergeTables(int $sourceTableId, int $targetTableId): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        if ($sourceTableId === $targetTableId) {
            throw ValidationException::withMessages([
                'target_table_id' => 'Meja tujuan tidak boleh sama dengan meja asal.',
            ]);
        }

        return DB::transaction(function () use ($merchantId, $sourceTableId, $targetTableId) {
            $ids = [$sourceTableId, $targetTableId];
            sort($ids);
            $locked = DiningTable::query()
                ->where('merchant_id', $merchantId)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var DiningTable|null $source */
            $source = $locked->get($sourceTableId);
            /** @var DiningTable|null $target */
            $target = $locked->get($targetTableId);

            if (! $source || ! $target || (int) $source->branch_id !== (int) $target->branch_id) {
                throw ValidationException::withMessages([
                    'target_table_id' => 'Kedua meja harus berada pada cabang yang sama.',
                ]);
            }

            if ($source->active_transaction_id === null || $target->active_transaction_id === null) {
                throw ValidationException::withMessages([
                    'target_table_id' => 'Kedua meja harus memiliki Open Bill aktif untuk digabungkan.',
                ]);
            }

            $sourceTx = $this->txService->show((int) $source->active_transaction_id);
            $targetTx = $this->txService->show((int) $target->active_transaction_id);

            $mergedItems = $targetTx->items->concat($sourceTx->items)->map(fn (SalesTransactionItem $item) => [
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
            ])->values()->all();

            $combinedGuests = (int) ($target->guest_count ?? 1) + (int) ($source->guest_count ?? 1);

            $updatedTargetTx = $this->txService->updateHeld($targetTx->id, [
                'items' => $mergedItems,
                'order_type' => SalesOrderType::DineIn->value,
                'dining_table_id' => (int) $target->id,
                'guest_count' => $combinedGuests,
                'notes' => trim(implode(' | ', array_filter([$targetTx->notes, $sourceTx->notes]))) ?: null,
            ]);

            $source->update([
                'status' => 'available',
                'active_transaction_id' => null,
                'guest_count' => null,
            ]);

            $target->update([
                'guest_count' => $combinedGuests,
            ]);

            // Pindahkan tiket KDS transaksi sumber ke transaksi & meja tujuan agar tidak terjadi duplikasi KOT
            $this->kdsService->mergeTicketsToTransaction(
                merchantId: $merchantId,
                sourceTransactionId: (int) $sourceTx->id,
                targetTransactionId: (int) $targetTx->id,
                targetTableCode: (string) $target->code,
                targetTableName: (string) $target->name,
            );

            // Soft-delete source held transaction
            SalesTransaction::query()
                ->where('merchant_id', $merchantId)
                ->where('id', $sourceTx->id)
                ->delete();

            return [
                'source_table' => $this->formatTable($source->fresh()->load(['area:id,name'])),
                'target_table' => $this->formatTable($target->fresh()->load(['area:id,name', 'activeTransaction.customer:id,name'])),
                'transaction' => $updatedTargetTx,
            ];
        });
    }

    /**
     * Split Bill per item: bayar sebagian item dari Open Bill meja menjadi transaksi completed terpisah.
     */
    public function splitBill(int $tableId, array $data, ?string $idempotencyKey = null): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        return DB::transaction(function () use ($merchantId, $tableId, $data, $idempotencyKey) {
            $table = DiningTable::query()
                ->where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->findOrFail($tableId);

            if ($table->active_transaction_id === null) {
                throw ValidationException::withMessages([
                    'table' => 'Meja ini tidak memiliki Open Bill aktif untuk di-split.',
                ]);
            }

            $openTx = $this->txService->show((int) $table->active_transaction_id);
            $splitItemIds = array_values(array_unique(array_map('intval', $data['item_ids'] ?? [])));

            if (empty($splitItemIds)) {
                throw ValidationException::withMessages([
                    'item_ids' => 'Pilih minimal 1 item untuk dipisahkan ke tagihan baru.',
                ]);
            }

            $splitItems = $openTx->items->whereIn('id', $splitItemIds)->values();
            $remainingItems = $openTx->items->whereNotIn('id', $splitItemIds)->values();

            if ($splitItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'item_ids' => 'Item yang dipilih tidak ditemukan pada tagihan meja ini.',
                ]);
            }

            // Jika seluruh item dipilih, langsung completeHeld transaksi meja tersebut
            if ($remainingItems->isEmpty()) {
                $paidTx = $this->txService->completeHeld($openTx->id, [
                    'cashier_shift_id' => $data['cashier_shift_id'] ?? null,
                    'customer_id' => $data['customer_id'] ?? $openTx->customer_id,
                    'order_type' => SalesOrderType::DineIn->value,
                    'payments' => $data['payments'] ?? [],
                ], $idempotencyKey);

                return [
                    'paid_transaction' => $paidTx,
                    'remaining_transaction' => null,
                    'table' => $this->formatTable($table->fresh()->load(['area:id,name'])),
                ];
            }

            $mapItems = fn ($collection) => $collection->map(fn (SalesTransactionItem $item) => [
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
            ])->values()->all();

            // 1. Buat transaksi completed baru untuk item yang di-split
            $paidTx = $this->txService->create([
                'branch_id' => (int) $table->branch_id,
                'cashier_shift_id' => $data['cashier_shift_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? $openTx->customer_id,
                'status' => SalesTransactionStatus::Completed->value,
                'order_type' => SalesOrderType::DineIn->value,
                'dining_table_id' => (int) $table->id,
                'notes' => "Split Bill Meja {$table->code}",
                'items' => $mapItems($splitItems),
                'payments' => $data['payments'] ?? [],
            ], $idempotencyKey);

            // 2. Perbarui Open Bill yang tersisa di meja
            $remainingTx = $this->txService->updateHeld($openTx->id, [
                'items' => $mapItems($remainingItems),
                'order_type' => SalesOrderType::DineIn->value,
                'dining_table_id' => (int) $table->id,
                'guest_count' => $table->guest_count,
            ]);

            return [
                'paid_transaction' => $paidTx,
                'remaining_transaction' => $remainingTx,
                'table' => $this->formatTable($table->fresh()->load(['area:id,name', 'activeTransaction.customer:id,name'])),
            ];
        });
    }

    /**
     * @param  array{pending: int, preparing: int, ready: int, total_active: int}|null  $kdsSummary
     * @return array<string, mixed>
     */
    private function formatTable(DiningTable $table, ?array $kdsSummary = null): array
    {
        $tx = $table->relationLoaded('activeTransaction') ? $table->activeTransaction : null;

        return [
            'id' => (int) $table->id,
            'branch_id' => (int) $table->branch_id,
            'dining_area_id' => $table->dining_area_id ? (int) $table->dining_area_id : null,
            'area_name' => $table->relationLoaded('area') ? $table->area?->name : null,
            'code' => (string) $table->code,
            'name' => (string) $table->name,
            'capacity' => (int) $table->capacity,
            'shape' => (string) $table->shape,
            'status' => (string) $table->status,
            'active_transaction_id' => $table->active_transaction_id ? (int) $table->active_transaction_id : null,
            'guest_count' => $table->guest_count !== null ? (int) $table->guest_count : null,
            'sort_order' => (int) $table->sort_order,
            'is_active' => (bool) $table->is_active,
            'active_transaction' => $tx ? [
                'id' => (int) $tx->id,
                'transaction_number' => (string) $tx->transaction_number,
                'queue_number' => $tx->queue_number,
                'grand_total' => (float) $tx->grand_total,
                'items_count' => isset($tx->items_count) ? (int) $tx->items_count : null,
                'customer_name' => $tx->relationLoaded('customer') ? $tx->customer?->name : null,
                'created_at' => $tx->created_at?->toIso8601String(),
            ] : null,
            'kds_summary' => $kdsSummary,
        ];
    }

    private function assertBranchBelongsToMerchant(int $merchantId, int $branchId): void
    {
        $exists = DB::table('branches')
            ->where('id', $branchId)
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['branch_id' => 'Cabang tidak ditemukan.']);
        }
    }

    private function assertAreaBelongsToBranch(int $merchantId, int $branchId, int $areaId): void
    {
        $exists = DB::table('dining_areas')
            ->where('id', $areaId)
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['dining_area_id' => 'Area meja tidak ditemukan pada cabang ini.']);
        }
    }
}
