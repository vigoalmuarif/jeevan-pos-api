<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Helpers\MerchantContext;
use InvalidArgumentException;

class PurchaseDocumentNumberService
{
    /** @var array<string, array{prefix:string,table:string}> */
    private const DOCUMENTS = [
        'purchase_order' => ['prefix' => 'PO', 'table' => 'purchase_orders'],
        'goods_receipt' => ['prefix' => 'GR', 'table' => 'goods_receipts'],
        'purchase_invoice' => ['prefix' => 'PI', 'table' => 'purchase_invoices'],
        'purchase_payment' => ['prefix' => 'PAY', 'table' => 'purchase_payments'],
        'purchase_request' => ['prefix' => 'PR', 'table' => 'purchase_requests'],
        'purchase_return' => ['prefix' => 'PRET', 'table' => 'purchase_returns'],
    ];

    public function generate(string $type): string
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $document = self::DOCUMENTS[$type] ?? throw new InvalidArgumentException('Jenis dokumen pembelian tidak dikenal.');

        if (DB::transactionLevel() === 0) {
            throw new LogicException('PurchaseDocumentNumberService::generate() harus dipanggil di dalam DB::transaction().');
        }

        DB::table('merchants')->where('id', $merchantId)->lock('for no key update')->first();

        $prefix = $document['prefix'].'-'.now()->format('Ymd').'-';
        $lastNumber = DB::table($document['table'])
            ->where('merchant_id', $merchantId)
            ->where('document_number', 'like', $prefix.'%')
            ->orderByDesc('document_number')
            ->value('document_number');

        $sequence = $lastNumber && preg_match('/-(\d+)$/', $lastNumber, $matches)
            ? (int) $matches[1] + 1
            : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
