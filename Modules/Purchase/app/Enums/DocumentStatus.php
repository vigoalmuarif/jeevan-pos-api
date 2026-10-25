<?php

namespace Modules\Purchase\Enums;

/**
 * Siklus hidup dokumen transaksi: Draft -> Posted -> Voided.
 *
 * Dipakai bersama oleh GoodsReceipt dan PurchaseInvoice (DRY), serta PurchasePayment
 * (pembayaran langsung berstatus Posted, tidak memiliki Draft).
 *
 *  - Draft  : boleh diubah/dihapus, BELUM berdampak ke stok maupun hutang.
 *  - Posted : berdampak (stok / hutang), dokumen terkunci.
 *  - Voided : dibatalkan lewat transaksi pembalik; dokumen tetap tersimpan untuk audit.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Diposting',
            self::Voided => 'Dibatalkan',
        };
    }

    /** Hanya draft yang boleh diedit atau dihapus. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canPost(): bool
    {
        return $this === self::Draft;
    }

    /** Hanya dokumen posted yang bisa di-void (draft cukup dihapus). */
    public function canVoid(): bool
    {
        return $this === self::Posted;
    }
}