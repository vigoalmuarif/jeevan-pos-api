<?php

namespace Modules\Inventory\Enums;

/**
 * Status transfer stok: Draft -> Shipped -> Received ; Cancelled.
 *  - Draft     : boleh diubah/dihapus, belum menggerakkan stok.
 *  - Shipped   : stok sudah keluar dari gudang asal, menunggu diterima.
 *  - Received  : stok masuk di gudang tujuan; dokumen final.
 *  - Cancelled : dari draft (tanpa mutasi), atau dari shipped (mutasi keluar dibalik).
 */
enum StockTransferStatus: string
{
    case Draft = 'draft';
    case Shipped = 'shipped';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Shipped => 'Dikirim',
            self::Received => 'Diterima',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canShip(): bool
    {
        return $this === self::Draft;
    }

    public function canReceive(): bool
    {
        return $this === self::Shipped;
    }

    /** Yang sudah diterima tidak dapat dibatalkan; koreksinya lewat transfer balik atau adjustment. */
    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Shipped], true);
    }
}