<?php

namespace Modules\Inventory\Enums;

/**
 * Status permohonan transfer stok:
 *  - Draft              : baru dibuat oleh staf cabang, masih dapat diubah/dihapus.
 *  - Submitted          : diajukan ke Gudang Pusat / Supervisor, menunggu persetujuan.
 *  - Approved           : kuantitas disetujui, siap dibuatkan dokumen pengiriman (StockTransfer).
 *  - PartialTransferred : sebagian kuantitas barang sudah diterbitkan transfer pengirimannya.
 *  - Transferred        : seluruh kuantitas barang telah tuntas diterbitkan transfer pengirimannya.
 *  - Rejected           : permohonan ditolak oleh pusat/supervisor.
 *  - Cancelled          : permohonan dibatalkan oleh peminta sebelum disetujui.
 */
enum StockRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case PartialTransferred = 'partial_transferred';
    case Transferred = 'transferred';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Diajukan',
            self::Approved => 'Disetujui',
            self::PartialTransferred => 'Dikirim Sebagian',
            self::Transferred => 'Selesai Dikirim',
            self::Rejected => 'Ditolak',
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

    public function canApprove(): bool
    {
        return $this === self::Submitted;
    }

    public function canReject(): bool
    {
        return $this === self::Submitted;
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Submitted], true);
    }

    public function canTransfer(): bool
    {
        return in_array($this, [self::Approved, self::PartialTransferred], true);
    }
}

