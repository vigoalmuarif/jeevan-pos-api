<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Purchase\Enums\PurchaseRequestSource;
use Modules\Purchase\Enums\PurchaseRequestStatus;
use Modules\Purchase\Exceptions\PurchaseRuleException;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseRequest;
use Modules\Purchase\Models\PurchaseRequestItem;
use Modules\Purchase\Support\Numeric;
use Modules\Purchase\Support\Quantity;
use Modules\Purchase\Support\SeparationOfDuties;

/**
 * Purchase Request (PR): permintaan pembelian internal. Tidak menggerakkan stok maupun hutang.
 * Fitur ini OPSIONAL: aktif hanya bila setting merchant purchase.request_enabled = true.
 *
 * Format input (sudah tervalidasi Form Request):
 *   $data  : branch_id?, warehouse_id, request_date, required_date?, notes?, source? (manual | low_stock)
 *   $lines : list of [product_variant_id, unit_id, unit_quantity, suggested_supplier_id?,
 *            estimated_unit_price?, on_hand_base_quantity?, notes?]
 *
 * Alur: draft -> submitted -> approved | rejected -> (dipesan sebagian / seluruhnya) ; cancelled / closed.
 * Persetujuan mengikuti pemisahan tugas (penyetuju bukan pembuat) bila setting
 * purchase.separate_approver aktif. Semua perubahan dalam DB::transaction() dengan kunci baris.
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
    ) {
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

    /** Hanya draft; item diganti seluruhnya (belum ada PO yang merujuknya). */
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
        dd('yhu');
        $this->assertEnabled();

        return DB::transaction(function () use ($request) {
            $request = $this->lock($request);

            if (! $request->status->canDecide()) {
                throw new PurchaseRuleException('Hanya Purchase Request yang sudah diajukan yang dapat disetujui.');
            }

            // Pemisahan tugas: pembuat tidak boleh menyetujui PR miliknya sendiri.
            SeparationOfDuties::assertApprover(
                $this->settings->get()->separateApprover,
                $request->created_by,
                auth('merchant')->id(),
                'PR'
            );

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
     * Jadikan item PR (dari satu atau beberapa PR yang disetujui) sebagai PO: satu PO per grup
     * (biasanya per supplier). Item PR dari PR berbeda yang sama (varian + satuan) dalam satu grup
     * DIGABUNG menjadi satu baris PO (penggabungan kebutuhan antar cabang) dengan link ke tiap asalnya.
     *
     * Bentuk input:
     *   $groups = [[
     *     'supplier_id', 'warehouse_id', 'order_date', 'expected_date'?, 'branch_id'?, 'notes'?,
     *     'is_tax_inclusive'?, 'discount_type'?, 'discount_value'?,
     *     'items' => [[ 'purchase_request_item_id', 'unit_id'? (default satuan item PR),
     *                   'unit_quantity', 'unit_price', 'discount_type'?, 'discount_value'?, 'tax_rate'? ]],
     *   ]]
     *
     * Satu transaksi: bila satu grup gagal, tidak ada PO yang terbentuk dan tidak ada qty PR yang berubah.
     * PO hasil konversi berstatus draft; pembeli menyetujuinya lewat PurchaseOrderService::approve().
     *
     * @return Collection<int,PurchaseOrder>
     */
    public function convertToOrders(array $groups): Collection
    {
        $this->assertEnabled();

        if ($groups === []) {
            throw ValidationException::withMessages(['groups' => 'Pilih minimal satu item untuk dijadikan PO.']);
        }

        return DB::transaction(function () use ($groups) {
            // ---- Persiapan (belum menulis apa pun) ------------------------------------------
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

            // Kunci PR lalu item PR (urutan tetap) agar dua konversi bersamaan tidak memakai sisa yang sama.
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

            $planned = [];   // id item PR => total qty (satuan dasar) yang direncanakan, lintas grup
            $prepared = [];  // hasil persiapan tiap grup
            foreach ($groups as $g => $group) {
                $prepared[$g] = $this->prepareGroup($g, $group, $items, $planned);
            }

            // Pemeriksaan sisa awal untuk pesan yang jelas; yang MENENTUKAN tetap di linker (di bawah kunci).
            foreach ($planned as $itemId => $qty) {
                if (bccomp($qty, (string) $items->get($itemId)->remaining_base_quantity, 4) > 0) {
                    throw new PurchaseRuleException("Qty yang dipesan melebihi sisa permintaan pada item PR #{$itemId}.");
                }
            }

            // ---- Eksekusi ---------------------------------------------------------------------
            $orders = collect();
            $allocations = [];

            foreach ($prepared as $p) {
                $order = $this->orders->create($p['header'], $p['lines']);

                // createMany menyisipkan berurutan, jadi urutan id item = urutan baris yang dikirim.
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

    /**
     * Validasi satu grup dan ubah menjadi input PO: baris yang sama (varian + satuan) digabung.
     *
     * @param  Collection<int,PurchaseRequestItem> $items item PR terkunci
     * @param  array<int,string>                   $planned total qty rencana per item PR (diperbarui)
     * @return array{header:array<string,mixed>, lines:list<array<string,mixed>>, allocations:list<array<string,mixed>>}
     */
    private function prepareGroup(int|string $g, array $group, Collection $items, array &$planned): array
    {
        $entries = array_values($group['items'] ?? []);
        if ($entries === []) {
            throw ValidationException::withMessages(["groups.{$g}.items" => 'Setiap PO harus memiliki minimal satu item.']);
        }

        $this->assertSupplier((int) $group['supplier_id'], "groups.{$g}.supplier_id");
        $this->assertWarehouse((int) $group['warehouse_id'], $group['branch_id'] ?? null);

        // Varian berasal dari item PR (bukan dari client); satuan default = satuan item PR.
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

        // Validasi produk/satuan dan ambil konversi dari master (sama dengan dokumen pembelian lain).
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

            // Harga, diskon, dan pajak harus sama untuk baris yang akan digabung.
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

        // Penanda internal tidak ikut ke PO.
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

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    /**
     * Validasi baris input PR dan ubah menjadi atribut item. Produk/satuan divalidasi oleh
     * PurchaseLineResolver (aturan yang sama dengan PO/GR/Stock Adjustment).
     *
     * @return list<array<string,mixed>>
     */
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

            $key = $line['product_variant_id'].':'.$line['unit_id'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["items.{$i}.product_variant_id" => 'Produk dengan satuan yang sama sudah ada pada baris lain.']);
            }
            $seen[$key] = true;

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

    /** Angka opsional >= 0 dengan maksimal 4 desimal; kosong -> null. */
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

    /** Gudang harus milik merchant dan aktif; cabang (bila diisi) harus terhubung ke gudang tersebut. */
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

    /** Supplier yang disarankan pada baris (opsional) harus milik merchant; satu query untuk semua baris. */
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

    /** Fitur PR hanya untuk merchant yang mengaktifkannya (setting purchase.request_enabled). */
    private function assertEnabled(): void
    {
        if (! $this->settings->get()->requestEnabled) {
            throw new PurchaseRuleException('Fitur Purchase Request belum diaktifkan untuk merchant ini.');
        }
    }

    /** Kunci baris dan baca ulang (status model dari pemanggil bisa sudah basi). */
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