<?php

namespace Modules\Purchase\Enums;

/**
 * Status pelunasan faktur pembelian (siklus hutang), terpisah dari DocumentStatus.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Belum Dibayar',
            self::Partial => 'Dibayar Sebagian',
            self::Paid => 'Lunas',
        };
    }

    /**
     * Satu-satunya tempat yang menentukan status bayar dari nominal (DRY).
     * Dipanggil PurchasePaymentService setiap kali paid_amount berubah (post / void pembayaran).
     *
     * Nominal bertipe string (hasil cast decimal) dan dibandingkan dengan bccomp
     * agar tidak terkena masalah pembulatan floating point pada angka uang.
     */
    public static function fromAmounts(string $paidAmount, string $grandTotal): self
    {
        // Belum ada pembayaran sama sekali.
        if (bccomp($paidAmount, '0', 4) <= 0) {
            return self::Unpaid;
        }

        // Pembayaran sudah menutup seluruh tagihan -> lunas.
        return bccomp($paidAmount, $grandTotal, 4) >= 0 ? self::Paid : self::Partial;
    }
}