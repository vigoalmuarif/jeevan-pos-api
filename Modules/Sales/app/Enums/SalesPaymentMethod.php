<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum SalesPaymentMethod: string
{
    case Cash = 'cash';
    case Qris = 'qris';
    case BankTransfer = 'bank_transfer';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case Ewallet = 'ewallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::BankTransfer => 'Transfer Bank',
            self::DebitCard => 'Kartu Debit',
            self::CreditCard => 'Kartu Kredit',
            self::Ewallet => 'E-Wallet',
        };
    }

    public function isCash(): bool
    {
        return $this === self::Cash;
    }
}
