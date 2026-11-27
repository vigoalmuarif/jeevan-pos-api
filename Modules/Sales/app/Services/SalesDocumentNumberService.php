<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Helpers\MerchantContext;

class SalesDocumentNumberService
{
    public function nextShiftNumber(): string
    {
        return $this->nextNumber('cashier_shifts', 'shift_number', 'SHF');
    }

    public function nextTransactionNumber(): string
    {
        return $this->nextNumber('sales_transactions', 'transaction_number', 'POS');
    }

    public function nextReturnNumber(): string
    {
        return $this->nextNumber('sales_returns', 'return_number', 'SRT');
    }

    /**
     * Generate nomor antrian harian per cabang (misal: Q-001, Q-002) secara atomik.
     */
    public function nextQueueNumber(int $branchId): string
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $period = now()->format('Ymd');
        $docType = 'QUE-B'.$branchId;
        $now = now();

        DB::table('sales_document_sequences')->insertOrIgnore([
            'merchant_id' => $merchantId,
            'doc_type' => $docType,
            'period' => $period,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $seqRow = DB::table('sales_document_sequences')
            ->where('merchant_id', $merchantId)
            ->where('doc_type', $docType)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        $next = (int) ($seqRow?->last_number ?? 0) + 1;

        DB::table('sales_document_sequences')
            ->where('merchant_id', $merchantId)
            ->where('doc_type', $docType)
            ->where('period', $period)
            ->update([
                'last_number' => $next,
                'updated_at' => $now,
            ]);

        return 'Q-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function nextNumber(string $table, string $column, string $code): string
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $period = now()->format('Ymd');
        $prefix = $code.'-'.$period.'-';
        $now = now();

        // Ensure sequence row exists for (merchant_id, doc_type, period) without locking the merchants table.
        DB::table('sales_document_sequences')->insertOrIgnore([
            'merchant_id' => $merchantId,
            'doc_type' => $code,
            'period' => $period,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $seqRow = DB::table('sales_document_sequences')
            ->where('merchant_id', $merchantId)
            ->where('doc_type', $code)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        $current = (int) ($seqRow?->last_number ?? 0);

        // Self-heal if existing records already exist for this period before sequence row was initialized.
        if ($current === 0) {
            $previous = DB::table($table)
                ->where('merchant_id', $merchantId)
                ->where($column, 'like', $prefix.'%')
                ->orderByDesc($column)
                ->value($column);

            if ($previous && preg_match('/-(\d+)$/', (string) $previous, $matches)) {
                $current = (int) $matches[1];
            }
        }

        $next = $current + 1;

        DB::table('sales_document_sequences')
            ->where('merchant_id', $merchantId)
            ->where('doc_type', $code)
            ->where('period', $period)
            ->update([
                'last_number' => $next,
                'updated_at' => $now,
            ]);

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
