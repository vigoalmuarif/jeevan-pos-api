<?php

namespace Modules\Purchase\Support;

use Modules\Purchase\Exceptions\PurchaseRuleException;

/**
 * Pemisahan tugas: penyetuju dokumen tidak boleh sama dengan pembuatnya (maker-checker).
 * Dipakai persetujuan PR dan PO; berlaku bila setting purchase.separate_approver aktif
 * (default aktif; merchant satu orang dapat mematikannya).
 *
 * Murni (tanpa database) agar dapat diuji.
 */
final class SeparationOfDuties
{
    public static function assertApprover(bool $enforce, mixed $creatorId, mixed $approverId, string $document): void
    {
        if (! $enforce) {
            return;
        }

        // Aturan aktif tetapi penyetuju tidak dikenali: tidak dapat diverifikasi, jadi ditolak.
        if ($approverId === null) {
            throw new PurchaseRuleException("Penyetuju {$document} tidak dapat dikenali.");
        }

        // Pembuat tidak tercatat (data lama / pengguna terhapus): tidak ada yang dibandingkan.
        if ($creatorId === null) {
            return;
        }

        if ((int) $creatorId === (int) $approverId) {
            throw new PurchaseRuleException("Pemisahan tugas: {$document} tidak dapat disetujui oleh pembuatnya sendiri.");
        }
    }
}