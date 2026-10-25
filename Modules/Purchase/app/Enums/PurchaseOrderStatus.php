<?php

namespace Modules\Purchase\Enums;

/**
 * Status Purchase Order.
 *
 * Ada DUA jenis perpindahan status:
 *  1. MANUAL (aksi user)   : Draft -> Approved, Draft/Approved -> Cancelled,
 *                            Approved/PartiallyReceived -> Closed.
 *  2. OTOMATIS (sistem)    : Approved <-> PartiallyReceived <-> Received.
 *     Dihitung ulang PurchaseOrderService dari purchase_order_items.received_base_quantity
 *     setiap kali goods receipt diposting atau di-void. Perpindahan ini TIDAK divalidasi
 *     lewat enum karena hasilnya selalu turunan dari data penerimaan.
 *
 * Closed dan Cancelled adalah status akhir (final).
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Disetujui',
            self::PartiallyReceived => 'Diterima Sebagian',
            self::Received => 'Diterima Penuh',
            self::Closed => 'Ditutup',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Goods receipt baru hanya boleh dibuat dari PO yang masih memiliki sisa penerimaan. */
    public function canReceive(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyReceived], true);
    }

    /**
     * Pembatalan hanya sebelum ada penerimaan. Setelah barang diterima, PO harus
     * ditutup (Closed), bukan dibatalkan. Service tetap memeriksa received_base_quantity = 0.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Approved], true);
    }

    /** Tutup manual PO yang sisa barangnya tidak akan dikirim supplier. */
    public function canBeClosed(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyReceived], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }
}