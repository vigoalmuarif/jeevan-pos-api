<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Helpers\MerchantContext;

class DocumentNumberService
{
    public function nextStockAdjustmentNumber(): string
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        // Serialize generation per merchant to avoid duplicate numbers on concurrent requests.
        DB::table('merchants')->where('id', $merchantId)->lockForUpdate()->first();

        $prefix = 'ADJ-'.now()->format('Ymd').'-';
        $previousNumber = DB::table('stock_adjustments')
            ->where('merchant_id', $merchantId)
            ->where('document_number', 'like', $prefix.'%')
            ->orderByDesc('document_number')
            ->value('document_number');

        $sequence = $previousNumber && preg_match('/-(\d+)$/', $previousNumber, $matches)
            ? ((int) $matches[1]) + 1
            : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
