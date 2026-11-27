<?php

namespace Modules\Purchase\Enums;

/**
 * Status Purchase Request.
 *
 * MANUAL (aksi user) : Draft -> Submitted -> Approved | Rejected ; Draft/Submitted/Approved -> Cancelled ;
 *                      Approved/PartiallyOrdered -> Closed.
 * OTOMATIS (sistem)  : Approved <-> PartiallyOrdered <-> Ordered, dihitung dari
 *                      purchase_request_items.ordered_base_quantity setiap PO dibuat / dihapus / dibatalkan.
 */
enum PurchaseRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PartiallyOrdered = 'partially_ordered';
    case Ordered = 'ordered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Diajukan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::PartiallyOrdered => 'Dipesan Sebagian',
            self::Ordered => 'Sudah Dipesan',
            self::Closed => 'Ditutup',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canSubmit(): bool
    {
        return $this === self::Draft;
    }

    /** Persetujuan dan penolakan hanya untuk PR yang sudah diajukan. */
    public function canDecide(): bool
    {
        return $this === self::Submitted;
    }

    /** PR dapat dijadikan PO selama masih ada sisa qty. */
    public function canConvert(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyOrdered], true);
    }

    /** Pembatalan hanya sebelum ada qty yang dipesan (service memeriksa ordered = 0). */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Approved], true);
    }

    /** Tutup manual PR yang sisanya tidak akan dipesan. */
    public function canBeClosed(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyOrdered], true);
    }

    /**
     * PR yang masih mewakili KEBUTUHAN terbuka: sisa qty-nya diperhitungkan sebagai "sudah diminta"
     * oleh rekomendasi stok minimum, agar barang yang sudah diminta tidak direkomendasikan lagi.
     */
    public function isOpenDemand(): bool
    {
        return in_array($this, [self::Submitted, self::Approved, self::PartiallyOrdered], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Ordered, self::Closed, self::Cancelled], true);
    }
}