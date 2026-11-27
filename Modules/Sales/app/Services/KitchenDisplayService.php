<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Services\ModuleEntitlementService;
use Modules\Sales\Models\KitchenStation;
use Modules\Sales\Models\KitchenTicket;
use Modules\Sales\Models\KitchenTicketItem;
use Modules\Sales\Models\SalesTransaction;
use Modules\Sales\Models\SalesTransactionItem;

class KitchenDisplayService
{
    public function __construct(
        private readonly ModuleEntitlementService $entitlementService,
        private readonly SalesDocumentNumberService $numberService,
    ) {}

    /**
     * Daftar station dapur/bar per cabang beserta category_ids (ZERO N+1 queries).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listStations(int $branchId): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $stations = KitchenStation::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $stationIds = $stations->pluck('id')->all();
        $catsByStation = empty($stationIds)
            ? collect()
            : DB::table('kitchen_station_categories')
                ->where('merchant_id', $merchantId)
                ->whereIn('kitchen_station_id', $stationIds)
                ->get(['kitchen_station_id', 'category_id'])
                ->groupBy('kitchen_station_id');

        return $stations->map(fn (KitchenStation $s) => [
            'id' => (int) $s->id,
            'branch_id' => (int) $s->branch_id,
            'code' => (string) $s->code,
            'name' => (string) $s->name,
            'sort_order' => (int) $s->sort_order,
            'is_default' => (bool) $s->is_default,
            'is_active' => (bool) $s->is_active,
            'category_ids' => $catsByStation->get($s->id, collect())->pluck('category_id')->map(fn ($id) => (int) $id)->values()->all(),
        ])->values()->all();
    }

    public function createStation(array $data): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $branchId = (int) $data['branch_id'];
        $code = strtoupper(trim((string) $data['code']));

        return DB::transaction(function () use ($merchantId, $branchId, $code, $data) {
            if (! empty($data['is_default'])) {
                KitchenStation::query()
                    ->where('merchant_id', $merchantId)
                    ->where('branch_id', $branchId)
                    ->update(['is_default' => false]);
            }

            $station = KitchenStation::query()->create([
                'merchant_id' => $merchantId,
                'branch_id' => $branchId,
                'code' => $code,
                'name' => trim((string) $data['name']),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            if (array_key_exists('category_ids', $data)) {
                $this->syncStationCategoriesInternal($merchantId, $station->id, $data['category_ids'] ?? []);
            }

            return collect($this->listStations($branchId))->firstWhere('id', $station->id);
        });
    }

    public function updateStation(int $stationId, array $data): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        return DB::transaction(function () use ($merchantId, $stationId, $data) {
            $station = KitchenStation::query()
                ->where('merchant_id', $merchantId)
                ->findOrFail($stationId);

            if (! empty($data['is_default'])) {
                KitchenStation::query()
                    ->where('merchant_id', $merchantId)
                    ->where('branch_id', $station->branch_id)
                    ->where('id', '<>', $station->id)
                    ->update(['is_default' => false]);
            }

            $station->update([
                'code' => array_key_exists('code', $data) ? strtoupper(trim((string) $data['code'])) : $station->code,
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $station->name,
                'sort_order' => array_key_exists('sort_order', $data) ? (int) $data['sort_order'] : $station->sort_order,
                'is_default' => array_key_exists('is_default', $data) ? (bool) $data['is_default'] : $station->is_default,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $station->is_active,
            ]);

            if (array_key_exists('category_ids', $data)) {
                $this->syncStationCategoriesInternal($merchantId, $station->id, $data['category_ids'] ?? []);
            }

            return collect($this->listStations((int) $station->branch_id))->firstWhere('id', $station->id);
        });
    }

    public function deleteStation(int $stationId): void
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $station = KitchenStation::query()
            ->where('merchant_id', $merchantId)
            ->findOrFail($stationId);

        $station->delete();
    }

    /**
     * Ambil daftar tiket KDS aktif pada cabang (FIFO, ZERO N+1 queries).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTickets(int $branchId, ?int $stationId = null, ?string $status = null): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $query = KitchenTicket::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->with([
                'station:id,code,name',
                'items',
            ]);

        if ($stationId !== null) {
            $query->where('kitchen_station_id', $stationId);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['pending', 'preparing', 'ready']);
        }

        return $query
            ->orderBy('sent_at')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (KitchenTicket $t) => $this->formatTicket($t))
            ->values()
            ->all();
    }

    /**
     * Kirim pesanan ke KDS dengan dukungan Incremental KOT & Station Routing (ZERO N+1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function dispatchForTransaction(SalesTransaction $transaction, bool $incremental = true, bool $bypassEntitlementCheck = false): array
    {
        $merchantId = (int) $transaction->merchant_id;

        if (! Schema::hasTable('kitchen_tickets') || ! Schema::hasTable('kitchen_ticket_items')) {
            return [];
        }

        if (! $bypassEntitlementCheck && Schema::hasTable('modules') && Schema::hasTable('merchant_modules')) {
            if (! $this->entitlementService->isEntitled($merchantId, 'kitchen_display')) {
                return [];
            }
        }

        $relations = ['customer:id,name', 'items'];
        if (Schema::hasTable('sales_transaction_item_modifiers')) {
            $relations = ['customer:id,name', 'items.modifiers'];
        }
        if (Schema::hasTable('dining_tables')) {
            $relations[] = 'diningTable:id,code,name';
        }
        $transaction->loadMissing($relations);

        if ($transaction->items->isEmpty()) {
            return [];
        }

        // Hitung kuantitas yang sudah pernah dikirim sebelumnya per fingerprint item (Incremental KOT)
        $sentQtyByKey = [];
        if ($incremental) {
            $existingTicketItems = DB::table('kitchen_ticket_items')
                ->join('kitchen_tickets', 'kitchen_tickets.id', '=', 'kitchen_ticket_items.kitchen_ticket_id')
                ->where('kitchen_tickets.merchant_id', $merchantId)
                ->where('kitchen_tickets.sales_transaction_id', $transaction->id)
                ->where('kitchen_tickets.status', '<>', 'voided')
                ->get(['kitchen_ticket_items.product_id', 'kitchen_ticket_items.variant_name', 'kitchen_ticket_items.modifiers_snapshot', 'kitchen_ticket_items.notes', 'kitchen_ticket_items.quantity']);

            foreach ($existingTicketItems as $row) {
                $mods = is_string($row->modifiers_snapshot) ? (json_decode($row->modifiers_snapshot, true) ?? []) : [];
                $fp = $this->itemFingerprint((int) $row->product_id, $row->variant_name, $mods, $row->notes);
                $sentQtyByKey[$fp] = round(($sentQtyByKey[$fp] ?? 0.0) + (float) $row->quantity, 4);
            }
        }

        // Hitung delta item yang perlu dikirim ke dapur
        $deltaItems = [];
        foreach ($transaction->items as $item) {
            /** @var SalesTransactionItem $item */
            $mods = $item->relationLoaded('modifiers')
                ? $item->modifiers->map(fn ($m) => [
                    'name' => (string) $m->modifier_option_name,
                    'quantity' => (int) $m->quantity,
                ])->values()->all()
                : [];

            // Course Firing (Hold & Fire): lewati item yang masih ditahan (is_held_for_kitchen = true)
            if ((bool) ($item->is_held_for_kitchen ?? false) === true) {
                continue;
            }

            $fp = $this->itemFingerprint((int) $item->product_id, $item->variant_name, $mods, $item->notes);
            $alreadySent = (float) ($sentQtyByKey[$fp] ?? 0.0);
            $currentQty = (float) $item->quantity;
            $deltaQty = round($currentQty - $alreadySent, 4);

            if ($alreadySent > 0) {
                $sentQtyByKey[$fp] = max(0.0, round($alreadySent - $currentQty, 4));
            }

            if ($deltaQty > 0) {
                $deltaItems[] = [
                    'sales_transaction_item_id' => (int) $item->id,
                    'product_id' => (int) $item->product_id,
                    'item_name' => (string) $item->product_name,
                    'variant_name' => $item->variant_name,
                    'unit_name' => $item->unit_name,
                    'quantity' => $deltaQty,
                    'modifiers_snapshot' => $mods,
                    'notes' => $item->notes,
                    'course_number' => max(1, min(3, (int) ($item->course_number ?? 1))),
                ];
            }
        }

        if (empty($deltaItems)) {
            return [];
        }

        // Batch-resolve station routing berdasarkan kategori produk (ZERO N+1 queries)
        $branchId = (int) $transaction->branch_id;
        $stations = KitchenStation::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->get();

        $defaultStationId = $stations->first()?->id ? (int) $stations->first()->id : null;
        $stationByCategory = [];

        if ($stations->isNotEmpty() && Schema::hasTable('kitchen_station_categories')) {
            $stationIds = $stations->pluck('id')->all();
            $links = DB::table('kitchen_station_categories')
                ->where('merchant_id', $merchantId)
                ->whereIn('kitchen_station_id', $stationIds)
                ->get(['kitchen_station_id', 'category_id']);

            foreach ($links as $link) {
                $stationByCategory[(int) $link->category_id] = (int) $link->kitchen_station_id;
            }
        }

        $productIds = array_values(array_unique(array_column($deltaItems, 'product_id')));
        $categoriesByProduct = [];
        if (! empty($stationByCategory) && ! empty($productIds) && Schema::hasTable('product_categories')) {
            $prodCats = DB::table('product_categories')
                ->whereIn('product_id', $productIds)
                ->orderByDesc('is_primary')
                ->get(['product_id', 'category_id']);

            foreach ($prodCats as $pc) {
                $categoriesByProduct[(int) $pc->product_id][] = (int) $pc->category_id;
            }
        }

        // Kelompokkan deltaItems per (station, course_number)
        $groupedByBucket = [];
        foreach ($deltaItems as $row) {
            $resolvedStationId = $defaultStationId;
            foreach (($categoriesByProduct[$row['product_id']] ?? []) as $catId) {
                if (isset($stationByCategory[$catId])) {
                    $resolvedStationId = $stationByCategory[$catId];
                    break;
                }
            }
            $courseNum = (int) ($row['course_number'] ?? 1);
            $bucketKey = ($resolvedStationId ?? 0).':'.$courseNum;
            $groupedByBucket[$bucketKey]['station_id'] = $resolvedStationId;
            $groupedByBucket[$bucketKey]['course_number'] = $courseNum;
            $groupedByBucket[$bucketKey]['items'][] = $row;
        }

        $createdTickets = [];
        $now = now();
        $hasCourseCol = Schema::hasColumn('kitchen_tickets', 'course_number');

        DB::transaction(function () use ($merchantId, $branchId, $transaction, $groupedByBucket, $now, $hasCourseCol, &$createdTickets) {
            foreach ($groupedByBucket as $bucket) {
                $stationId = $bucket['station_id'];
                $courseNumber = (int) $bucket['course_number'];
                $items = $bucket['items'];
                $ticketNumber = $this->nextTicketNumber($branchId);

                $ticketData = [
                    'merchant_id' => $merchantId,
                    'branch_id' => $branchId,
                    'sales_transaction_id' => $transaction->id,
                    'kitchen_station_id' => $stationId,
                    'ticket_number' => $ticketNumber,
                    'order_type' => $transaction->order_type?->value ?? 'dine_in',
                    'queue_number' => $transaction->queue_number,
                    'table_code' => $transaction->relationLoaded('diningTable') ? $transaction->diningTable?->code : null,
                    'table_name' => $transaction->relationLoaded('diningTable') ? $transaction->diningTable?->name : null,
                    'customer_name' => $transaction->relationLoaded('customer') ? $transaction->customer?->name : null,
                    'status' => 'pending',
                    'notes' => $transaction->notes,
                    'sent_at' => $now,
                ];

                if ($hasCourseCol) {
                    $ticketData['course_number'] = $courseNumber;
                }

                if (Schema::hasColumn('kitchen_tickets', 'sales_channel')) {
                    $ticketData['sales_channel'] = $transaction->sales_channel ?? null;
                    $ticketData['channel_order_ref'] = $transaction->channel_order_ref ?? null;
                }

                $ticket = KitchenTicket::query()->create($ticketData);

                $itemRows = array_map(fn (array $row) => [
                    'merchant_id' => $merchantId,
                    'kitchen_ticket_id' => $ticket->id,
                    'sales_transaction_item_id' => $row['sales_transaction_item_id'],
                    'product_id' => $row['product_id'],
                    'item_name' => $row['item_name'],
                    'variant_name' => $row['variant_name'],
                    'unit_name' => $row['unit_name'],
                    'quantity' => $row['quantity'],
                    'modifiers_snapshot' => json_encode($row['modifiers_snapshot']),
                    'notes' => $row['notes'],
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $items);

                KitchenTicketItem::query()->insert($itemRows);

                $createdTickets[] = $this->formatTicket($ticket->load(['station:id,code,name', 'items']));
            }
        });

        return $createdTickets;
    }

    public function updateTicketStatus(int $ticketId, string $status): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        return DB::transaction(function () use ($merchantId, $ticketId, $status) {
            $ticket = KitchenTicket::query()
                ->where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->findOrFail($ticketId);

            if ($ticket->status === 'voided') {
                throw ValidationException::withMessages([
                    'status' => 'Tiket yang sudah dibatalkan tidak dapat diubah statusnya.',
                ]);
            }

            $now = now();
            $update = ['status' => $status];
            if ($status === 'preparing' && $ticket->started_at === null) {
                $update['started_at'] = $now;
            }
            if ($status === 'ready' && $ticket->ready_at === null) {
                $update['ready_at'] = $now;
            }
            if ($status === 'served' && $ticket->served_at === null) {
                $update['served_at'] = $now;
            }

            $ticket->update($update);
            KitchenTicketItem::query()
                ->where('merchant_id', $merchantId)
                ->where('kitchen_ticket_id', $ticket->id)
                ->update(['status' => $status, 'updated_at' => $now]);

            return $this->formatTicket($ticket->fresh()->load(['station:id,code,name', 'items']));
        });
    }

    public function voidTicketsForTransaction(int $transactionId): void
    {
        if (! Schema::hasTable('kitchen_tickets')) {
            return;
        }

        $merchantId = MerchantContext::id();
        if (! $merchantId) {
            return;
        }

        $ticketIds = KitchenTicket::query()
            ->where('merchant_id', $merchantId)
            ->where('sales_transaction_id', $transactionId)
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->pluck('id')
            ->all();

        if (empty($ticketIds)) {
            return;
        }

        $now = now();
        KitchenTicket::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('id', $ticketIds)
            ->update(['status' => 'voided', 'updated_at' => $now]);

        KitchenTicketItem::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('kitchen_ticket_id', $ticketIds)
            ->update(['status' => 'voided', 'updated_at' => $now]);
    }

    /**
     * Sinkronkan pengurangan/penghapusan item pada Open Bill ke tiket KDS aktif:
     * item yang dikurangi/dihapus akan di-void atau dikurangi qty-nya di KDS
     * agar dapur tidak memasak pesanan yang sudah dibatalkan tamu.
     */
    public function syncRemovalsForTransaction(SalesTransaction $transaction): int
    {
        return count($this->syncRemovalsWithDetails($transaction));
    }

    /**
     * Sinkronkan pengurangan/penghapusan item Open Bill ke tiket KDS aktif
     * dan kembalikan detail setiap item terkirim yang dikurangi/dibatalkan (untuk audit void & Manager PIN).
     *
     * @return array<int, array{product_id: int, item_name: string, variant_name: ?string, voided_qty: float}>
     */
    public function syncRemovalsWithDetails(SalesTransaction $transaction): array
    {
        if (! Schema::hasTable('kitchen_tickets') || ! Schema::hasTable('kitchen_ticket_items')) {
            return [];
        }

        $merchantId = (int) $transaction->merchant_id;

        $relations = ['items'];
        if (Schema::hasTable('sales_transaction_item_modifiers')) {
            $relations = ['items.modifiers'];
        }
        $transaction->loadMissing($relations);

        // Hitung total kuantitas yang diinginkan saat ini per fingerprint
        $desiredQtyByFp = [];
        foreach ($transaction->items as $item) {
            /** @var SalesTransactionItem $item */
            $mods = $item->relationLoaded('modifiers')
                ? $item->modifiers->map(fn ($m) => [
                    'name' => (string) $m->modifier_option_name,
                    'quantity' => (int) $m->quantity,
                ])->values()->all()
                : [];
            $fp = $this->itemFingerprint((int) $item->product_id, $item->variant_name, $mods, $item->notes);
            $desiredQtyByFp[$fp] = round(($desiredQtyByFp[$fp] ?? 0.0) + (float) $item->quantity, 4);
        }

        // Ambil seluruh item tiket KDS non-voided (1 query) urut terbaru dulu agar pengurangan membatalkan kiriman terakhir
        $ticketItems = DB::table('kitchen_ticket_items')
            ->join('kitchen_tickets', 'kitchen_tickets.id', '=', 'kitchen_ticket_items.kitchen_ticket_id')
            ->where('kitchen_tickets.merchant_id', $merchantId)
            ->where('kitchen_tickets.sales_transaction_id', $transaction->id)
            ->where('kitchen_tickets.status', '<>', 'voided')
            ->where('kitchen_ticket_items.status', '<>', 'voided')
            ->orderByDesc('kitchen_tickets.id')
            ->orderByDesc('kitchen_ticket_items.id')
            ->get([
                'kitchen_ticket_items.id',
                'kitchen_ticket_items.kitchen_ticket_id',
                'kitchen_ticket_items.product_id',
                'kitchen_ticket_items.item_name',
                'kitchen_ticket_items.variant_name',
                'kitchen_ticket_items.modifiers_snapshot',
                'kitchen_ticket_items.notes',
                'kitchen_ticket_items.quantity',
                'kitchen_ticket_items.status',
            ]);

        if ($ticketItems->isEmpty()) {
            return [];
        }

        $groupedSent = [];
        $totalSentByFp = [];
        foreach ($ticketItems as $row) {
            $mods = is_string($row->modifiers_snapshot) ? (json_decode($row->modifiers_snapshot, true) ?? []) : [];
            $fp = $this->itemFingerprint((int) $row->product_id, $row->variant_name, $mods, $row->notes);
            $groupedSent[$fp][] = $row;
            $totalSentByFp[$fp] = round(($totalSentByFp[$fp] ?? 0.0) + (float) $row->quantity, 4);
        }

        $now = now();
        $voidedItemIds = [];
        $affectedTicketIds = [];
        $voidedDetails = [];

        foreach ($totalSentByFp as $fp => $sentQty) {
            $desiredQty = (float) ($desiredQtyByFp[$fp] ?? 0.0);
            $excess = round($sentQty - $desiredQty, 4);
            if ($excess <= 0) {
                continue;
            }

            $reducedForFp = 0.0;
            $sampleRow = $groupedSent[$fp][0] ?? null;

            foreach ($groupedSent[$fp] as $row) {
                if ($excess <= 0) {
                    break;
                }
                // Hanya item yang belum disajikan (pending/preparing/ready) yang bisa dibatalkan/dikurangi
                if (! in_array((string) $row->status, ['pending', 'preparing', 'ready'], true)) {
                    continue;
                }

                $rowQty = (float) $row->quantity;
                $affectedTicketIds[] = (int) $row->kitchen_ticket_id;

                if ($rowQty <= $excess + 0.0001) {
                    $voidedItemIds[] = (int) $row->id;
                    $excess = round($excess - $rowQty, 4);
                    $reducedForFp = round($reducedForFp + $rowQty, 4);
                } else {
                    $newQty = round($rowQty - $excess, 4);
                    DB::table('kitchen_ticket_items')
                        ->where('merchant_id', $merchantId)
                        ->where('id', (int) $row->id)
                        ->update(['quantity' => $newQty, 'updated_at' => $now]);
                    $reducedForFp = round($reducedForFp + $excess, 4);
                    $excess = 0.0;
                }
            }

            if ($reducedForFp > 0 && $sampleRow !== null) {
                $voidedDetails[] = [
                    'product_id' => (int) $sampleRow->product_id,
                    'item_name' => (string) ($sampleRow->item_name ?? 'Menu'),
                    'variant_name' => $sampleRow->variant_name ? (string) $sampleRow->variant_name : null,
                    'voided_qty' => $reducedForFp,
                ];
            }
        }

        if (! empty($voidedItemIds)) {
            DB::table('kitchen_ticket_items')
                ->where('merchant_id', $merchantId)
                ->whereIn('id', $voidedItemIds)
                ->update(['status' => 'voided', 'updated_at' => $now]);
        }

        // Jika seluruh item dalam suatu tiket KDS sudah voided, otomatis void tiket induknya
        $uniqueTicketIds = array_values(array_unique($affectedTicketIds));
        if (! empty($uniqueTicketIds)) {
            $ticketsStillActive = DB::table('kitchen_ticket_items')
                ->where('merchant_id', $merchantId)
                ->whereIn('kitchen_ticket_id', $uniqueTicketIds)
                ->where('status', '<>', 'voided')
                ->distinct()
                ->pluck('kitchen_ticket_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $ticketsToVoid = array_values(array_diff($uniqueTicketIds, $ticketsStillActive));
            if (! empty($ticketsToVoid)) {
                DB::table('kitchen_tickets')
                    ->where('merchant_id', $merchantId)
                    ->whereIn('id', $ticketsToVoid)
                    ->update(['status' => 'voided', 'updated_at' => $now]);
            }
        }

        return $voidedDetails;
    }

    /**
     * Pasang atribut transien `sent_to_kitchen_qty` pada setiap item transaksi (1 query, ZERO N+1)
     * agar kasir melihat indikator item mana yang sudah dikirim ke dapur.
     */
    public function attachSentKitchenQuantities(SalesTransaction $transaction): void
    {
        if (! Schema::hasTable('kitchen_tickets') || ! Schema::hasTable('kitchen_ticket_items')) {
            return;
        }
        if (! $transaction->relationLoaded('items') || $transaction->items->isEmpty()) {
            return;
        }

        $merchantId = (int) $transaction->merchant_id;
        $rows = DB::table('kitchen_ticket_items')
            ->join('kitchen_tickets', 'kitchen_tickets.id', '=', 'kitchen_ticket_items.kitchen_ticket_id')
            ->where('kitchen_tickets.merchant_id', $merchantId)
            ->where('kitchen_tickets.sales_transaction_id', $transaction->id)
            ->where('kitchen_tickets.status', '<>', 'voided')
            ->where('kitchen_ticket_items.status', '<>', 'voided')
            ->get([
                'kitchen_ticket_items.product_id',
                'kitchen_ticket_items.variant_name',
                'kitchen_ticket_items.modifiers_snapshot',
                'kitchen_ticket_items.notes',
                'kitchen_ticket_items.quantity',
            ]);

        $sentByFp = [];
        foreach ($rows as $row) {
            $mods = is_string($row->modifiers_snapshot) ? (json_decode($row->modifiers_snapshot, true) ?? []) : [];
            $fp = $this->itemFingerprint((int) $row->product_id, $row->variant_name, $mods, $row->notes);
            $sentByFp[$fp] = round(($sentByFp[$fp] ?? 0.0) + (float) $row->quantity, 4);
        }

        foreach ($transaction->items as $item) {
            /** @var SalesTransactionItem $item */
            $mods = $item->relationLoaded('modifiers')
                ? $item->modifiers->map(fn ($m) => [
                    'name' => (string) $m->modifier_option_name,
                    'quantity' => (int) $m->quantity,
                ])->values()->all()
                : [];
            $fp = $this->itemFingerprint((int) $item->product_id, $item->variant_name, $mods, $item->notes);
            $available = (float) ($sentByFp[$fp] ?? 0.0);
            $assigned = min((float) $item->quantity, $available);
            $item->setAttribute('sent_to_kitchen_qty', round($assigned, 4));
            if ($available > 0) {
                $sentByFp[$fp] = max(0.0, round($available - $assigned, 4));
            }
        }
    }

    /**
     * Sinkronkan nomor & nama meja pada seluruh tiket KDS aktif saat tamu pindah meja
     * (berlaku untuk semua tipe pembayaran: Pay-First, Pay-Later, maupun Flexible).
     */
    public function reassignTableForTickets(
        int $merchantId,
        int $branchId,
        string $fromTableCode,
        string $toTableCode,
        string $toTableName,
        ?int $transactionId = null,
    ): int {
        if (! Schema::hasTable('kitchen_tickets')) {
            return 0;
        }

        $query = KitchenTicket::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->where(function ($q) use ($fromTableCode, $transactionId) {
                $q->where('table_code', $fromTableCode);
                if ($transactionId !== null) {
                    $q->orWhere('sales_transaction_id', $transactionId);
                }
            });

        return (int) $query->update([
            'table_code' => $toTableCode,
            'table_name' => $toTableName,
            'updated_at' => now(),
        ]);
    }

    /**
     * Pindahkan seluruh tiket KDS milik transaksi sumber ke transaksi tujuan saat Gabung Meja,
     * agar Incremental KOT tidak mengirim ulang item yang sudah dimasak.
     */
    public function mergeTicketsToTransaction(
        int $merchantId,
        int $sourceTransactionId,
        int $targetTransactionId,
        string $targetTableCode,
        string $targetTableName,
    ): int {
        if (! Schema::hasTable('kitchen_tickets')) {
            return 0;
        }

        return (int) KitchenTicket::query()
            ->where('merchant_id', $merchantId)
            ->where('sales_transaction_id', $sourceTransactionId)
            ->where('status', '<>', 'voided')
            ->update([
                'sales_transaction_id' => $targetTransactionId,
                'table_code' => $targetTableCode,
                'table_name' => $targetTableName,
                'updated_at' => now(),
            ]);
    }

    /**
     * Ringkasan status tiket KDS aktif per kode meja dalam 1 query agregat (ZERO N+1).
     *
     * @param  array<int, string>  $tableCodes
     * @return array<string, array{pending: int, preparing: int, ready: int, total_active: int}>
     */
    public function batchTableKdsSummary(int $merchantId, int $branchId, array $tableCodes): array
    {
        if (empty($tableCodes) || ! Schema::hasTable('kitchen_tickets')) {
            return [];
        }

        $rows = DB::table('kitchen_tickets')
            ->selectRaw('table_code, status, COUNT(*) as ticket_count')
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->whereIn('table_code', array_values(array_unique($tableCodes)))
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->groupBy('table_code', 'status')
            ->get();

        $summary = [];
        foreach ($rows as $row) {
            $code = (string) $row->table_code;
            if (! isset($summary[$code])) {
                $summary[$code] = [
                    'pending' => 0,
                    'preparing' => 0,
                    'ready' => 0,
                    'total_active' => 0,
                ];
            }
            $status = (string) $row->status;
            $count = (int) $row->ticket_count;
            if (isset($summary[$code][$status])) {
                $summary[$code][$status] = $count;
                $summary[$code]['total_active'] += $count;
            }
        }

        return $summary;
    }

    /**
     * @param  array<int, int>  $categoryIds
     */
    private function syncStationCategoriesInternal(int $merchantId, int $stationId, array $categoryIds): void
    {
        DB::table('kitchen_station_categories')
            ->where('merchant_id', $merchantId)
            ->where('kitchen_station_id', $stationId)
            ->delete();

        $unique = array_values(array_unique(array_filter(array_map('intval', $categoryIds), fn (int $id) => $id > 0)));
        if (empty($unique)) {
            return;
        }

        $now = now();
        $rows = array_map(fn (int $catId) => [
            'merchant_id' => $merchantId,
            'kitchen_station_id' => $stationId,
            'category_id' => $catId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $unique);

        DB::table('kitchen_station_categories')->insert($rows);
    }

    private function nextTicketNumber(int $branchId): string
    {
        $merchantId = MerchantContext::id();
        $period = now()->format('Ymd');
        $docType = 'KOT-B'.$branchId;
        $now = now();

        DB::table('sales_document_sequences')->insertOrIgnore([
            'merchant_id' => $merchantId,
            'doc_type' => $docType,
            'period' => $period,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $seqRow = DB::table('sales_document_sequences')
            ->where('merchant_id', $merchantId)
            ->where('doc_type', $docType)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        $next = (int) ($seqRow?->last_number ?? 0) + 1;

        DB::table('sales_document_sequences')
            ->where('merchant_id', $merchantId)
            ->where('doc_type', $docType)
            ->where('period', $period)
            ->update(['last_number' => $next, 'updated_at' => $now]);

        return 'KOT-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $modifiers
     */
    private function itemFingerprint(int $productId, ?string $variantName, array $modifiers, ?string $notes): string
    {
        $modParts = array_map(
            fn (array $m) => ($m['name'] ?? '').':'.($m['quantity'] ?? 1),
            $modifiers,
        );
        sort($modParts);

        return implode('|', [
            $productId,
            trim((string) $variantName),
            implode(',', $modParts),
            trim((string) $notes),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTicket(KitchenTicket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'branch_id' => (int) $ticket->branch_id,
            'sales_transaction_id' => (int) $ticket->sales_transaction_id,
            'kitchen_station_id' => $ticket->kitchen_station_id ? (int) $ticket->kitchen_station_id : null,
            'station' => $ticket->relationLoaded('station') && $ticket->station ? [
                'id' => (int) $ticket->station->id,
                'code' => (string) $ticket->station->code,
                'name' => (string) $ticket->station->name,
            ] : null,
            'ticket_number' => (string) $ticket->ticket_number,
            'order_type' => (string) $ticket->order_type,
            'course_number' => (int) ($ticket->course_number ?? 1),
            'sales_channel' => $ticket->sales_channel ?? null,
            'channel_order_ref' => $ticket->channel_order_ref ?? null,
            'queue_number' => $ticket->queue_number,
            'table_code' => $ticket->table_code,
            'table_name' => $ticket->table_name,
            'customer_name' => $ticket->customer_name,
            'status' => (string) $ticket->status,
            'notes' => $ticket->notes,
            'sent_at' => $ticket->sent_at?->toIso8601String(),
            'started_at' => $ticket->started_at?->toIso8601String(),
            'ready_at' => $ticket->ready_at?->toIso8601String(),
            'served_at' => $ticket->served_at?->toIso8601String(),
            'items' => $ticket->relationLoaded('items')
                ? $ticket->items->map(fn (KitchenTicketItem $item) => [
                    'id' => (int) $item->id,
                    'product_id' => (int) $item->product_id,
                    'item_name' => (string) $item->item_name,
                    'variant_name' => $item->variant_name,
                    'unit_name' => $item->unit_name,
                    'quantity' => (float) $item->quantity,
                    'modifiers' => is_array($item->modifiers_snapshot) ? $item->modifiers_snapshot : [],
                    'notes' => $item->notes,
                    'status' => (string) $item->status,
                ])->values()->all()
                : [],
        ];
    }
}
