<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Modules\Purchase\Enums\PurchaseOrderStatus;
use Modules\Purchase\Exceptions\PurchaseRuleException;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Siklus hidup Purchase Order: create/update/delete draft, approve, cancel, close,
 * serta pencatatan penerimaan barang (dipanggil GoodsReceiptService).
 *
 * Format input (sudah tervalidasi Form Request):
 *   $data  : branch_id?, supplier_id, warehouse_id, order_date, expected_date?, notes?,
 *            is_tax_inclusive?, discount_type?, discount_value?
 *   $lines : list of [product_variant_id, unit_id, unit_quantity, unit_price,
 *            discount_type?, discount_value?, tax_rate? (null = default), notes?]
 *
 * Semua method yang mengubah data berjalan dalam DB::transaction() dan MENGUNCI baris PO
 * (lockForUpdate) sebelum memeriksa status, sehingga dua aksi bersamaan tidak lolos keduanya.
 */
class PurchaseOrderService
{
    /** Kolom header yang boleh berasal dari input user. */
    private const HEADER_FIELDS = ['branch_id', 'supplier_id', 'warehouse_id', 'order_date', 'expected_date', 'notes'];

    public function __construct(
        private readonly PurchaseDocumentPreparer $preparer,
        private readonly PurchaseDocumentNumberService $numbers,
    ) {}

    public function create(array $data, array $lines): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $lines) {
            $prepared = $this->preparer->prepare($data, $lines, forReceipt: false);

            $order = new PurchaseOrder();
            $order->fill(array_merge(
                Arr::only($data, self::HEADER_FIELDS),
                $prepared->header,
                ['document_number' => $this->numbers->generate('purchase_order')],
            ));
            $order->save();
            $order->items()->createMany($prepared->items);

            return $order->load('items');
        });
    }

    /** Hanya draft yang boleh diubah; item diganti seluruhnya (belum ada transaksi yang merujuknya). */
    public function update(PurchaseOrder $order, array $data, array $lines): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data, $lines) {
            $order = $this->lock($order);

            if (! $order->status->isEditable()) {
                throw new PurchaseRuleException('Hanya PO berstatus draft yang dapat diubah.');
            }

            $prepared = $this->preparer->prepare($data, $lines, forReceipt: false);

            $order->fill(array_merge(Arr::only($data, self::HEADER_FIELDS), $prepared->header))->save();
            $order->items()->delete();
            $order->items()->createMany($prepared->items);

            return $order->load('items');
        });
    }

    public function delete(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = $this->lock($order);

            if (! $order->status->isEditable()) {
                throw new PurchaseRuleException('Hanya PO berstatus draft yang dapat dihapus. PO lain dibatalkan atau ditutup.');
            }

            $order->delete(); // soft delete
        });
    }

    public function approve(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->lock($order);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new PurchaseRuleException('Hanya PO berstatus draft yang dapat disetujui.');
            }
            if (! $order->items()->exists()) {
                throw new PurchaseRuleException('PO tanpa item tidak dapat disetujui.');
            }

            $order->forceFill([
                'status' => PurchaseOrderStatus::Approved,
                'approved_at' => now(),
                'approved_by' => auth('merchant')->id(),
            ])->save();

            return $order;
        });
    }

    public function cancel(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = $this->lock($order);

            if (! $order->status->canBeCancelled()) {
                throw new PurchaseRuleException('PO pada status ini tidak dapat dibatalkan.');
            }
            // Setelah ada barang diterima, PO harus DITUTUP (close), bukan dibatalkan.
            if ($order->items()->where('received_base_quantity', '>', 0)->exists()) {
                throw new PurchaseRuleException('PO sudah memiliki penerimaan barang. Gunakan Tutup PO, bukan Batalkan.');
            }
            // Draft penerimaan yang masih menunjuk PO ini akan gagal diposting; minta dihapus dulu.
            if ($order->goodsReceipts()->where('status', 'draft')->exists()) {
                throw new PurchaseRuleException('Masih ada draft penerimaan barang untuk PO ini. Hapus drafnya terlebih dahulu.');
            }

            $order->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => auth('merchant')->id(),
                'cancel_reason' => $reason,
            ])->save();

            return $order;
        });
    }

    /** Tutup manual PO yang sisa barangnya tidak akan dikirim supplier. */
    public function close(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->lock($order);

            if (! $order->status->canBeClosed()) {
                throw new PurchaseRuleException('Hanya PO yang disetujui atau diterima sebagian yang dapat ditutup.');
            }

            $order->forceFill(['status' => PurchaseOrderStatus::Closed])->save();

            return $order;
        });
    }

    /**
     * Catat penerimaan: tambahkan qty (satuan dasar) yang diterima per item PO.
     * Dipanggil GoodsReceiptService::post() DI DALAM transaksinya.
     *
     * @param array<int,string> $baseQtyByItemId id item PO => qty satuan dasar
     */
    public function applyReceipt(PurchaseOrder $order, array $baseQtyByItemId): void
    {
        $this->adjustReceived($order, $baseQtyByItemId, add: true);
    }

    /**
     * Batalkan pencatatan penerimaan (saat goods receipt di-void).
     *
     * @param array<int,string> $baseQtyByItemId
     */
    public function revertReceipt(PurchaseOrder $order, array $baseQtyByItemId): void
    {
        $this->adjustReceived($order, $baseQtyByItemId, add: false);
    }

    private function adjustReceived(PurchaseOrder $order, array $baseQtyByItemId, bool $add): void
    {
        $order = $this->lock($order);

        // Menerima barang hanya untuk PO yang masih terbuka. Membatalkan penerimaan (void)
        // tetap boleh pada PO yang sudah ditutup, agar data tidak terkunci.
        if ($add && ! $order->status->canReceive()) {
            throw new PurchaseRuleException('PO pada status ini tidak dapat menerima barang.');
        }

        // Item dikunci agar dua penerimaan bersamaan tidak sama-sama lolos cek sisa.
        $items = $order->items()->lockForUpdate()->get()->keyBy('id');

        foreach ($baseQtyByItemId as $itemId => $qty) {
            $item = $items->get((int) $itemId)
                ?? throw new PurchaseRuleException("Item PO #{$itemId} tidak ditemukan.");

            $new = $add
                ? bcadd((string) $item->received_base_quantity, $qty, 4)
                : bcsub((string) $item->received_base_quantity, $qty, 4);

            if (bccomp($new, '0', 4) < 0) {
                throw new PurchaseRuleException("Qty diterima item PO #{$itemId} tidak boleh negatif.");
            }
            if (bccomp($new, (string) $item->base_quantity, 4) > 0) {
                throw new PurchaseRuleException("Penerimaan melebihi jumlah yang dipesan pada item PO #{$itemId}.");
            }

            $item->forceFill(['received_base_quantity' => $new])->save();
        }

        $this->syncStatus($order, $items);
    }

    /**
     * Status PO TURUNAN dari data penerimaan (bukan diisi manual):
     *   semua item terpenuhi -> Received; ada yang diterima -> PartiallyReceived; belum ada -> Approved.
     * Hanya berlaku untuk PO terbuka; Closed/Cancelled/Draft tidak diubah.
     *
     * @param Collection<int,\Modules\Purchase\Models\PurchaseOrderItem> $items
     */
    private function syncStatus(PurchaseOrder $order, Collection $items): void
    {
        if (! in_array($order->status, [
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::PartiallyReceived,
            PurchaseOrderStatus::Received,
        ], true)) {
            return;
        }

        $anyReceived = $items->contains(fn($i) => bccomp((string) $i->received_base_quantity, '0', 4) > 0);
        $allReceived = $items->every(fn($i) => bccomp((string) $i->received_base_quantity, (string) $i->base_quantity, 4) >= 0);

        $status = match (true) {
            $allReceived => PurchaseOrderStatus::Received,
            $anyReceived => PurchaseOrderStatus::PartiallyReceived,
            default => PurchaseOrderStatus::Approved,
        };

        if ($status !== $order->status) {
            $order->forceFill(['status' => $status])->save();
        }
    }

    /**
     * Kunci baris PO dan baca ULANG dari database: status di model yang dikirim pemanggil
     * bisa sudah basi. MerchantScope membuat PO merchant lain tidak ditemukan.
     */
    private function lock(PurchaseOrder $order): PurchaseOrder
    {
        return PurchaseOrder::query()->lockForUpdate()->findOrFail($order->getKey());
    }
}
