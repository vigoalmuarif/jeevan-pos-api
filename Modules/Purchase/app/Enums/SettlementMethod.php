<?php

namespace Modules\Purchase\Enums;

enum SettlementMethod: string
{
    case InvoiceDeduction = 'invoice_deduction';
    case CashRefund = 'cash_refund';
    case CreditBalance = 'credit_balance';

    public function label(): string
    {
        return match ($this) {
            self::InvoiceDeduction => 'Potong Faktur',
            self::CashRefund => 'Pengembalian Dana Tunai / Transfer',
            self::CreditBalance => 'Saldo Deposit Supplier',
        };
    }
}

