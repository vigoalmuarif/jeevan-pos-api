<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Sales\Enums\CashierShiftStatus;
use Modules\Sales\Enums\CashMovementType;
use Modules\Sales\Enums\SalesPaymentMethod;
use Modules\Sales\Enums\SalesReturnStatus;
use Modules\Sales\Enums\SalesTransactionStatus;
use Modules\Sales\Models\CashierShift;
use Modules\Sales\Models\CashierShiftMovement;

class CashierShiftService
{
    public function __construct(
        private readonly SalesDocumentNumberService $numberService,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $query = CashierShift::query()
            ->where('merchant_id', $merchantId)
            ->with(['branch:id,name', 'user:id,name', 'closedByUser:id,name']);

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('shift_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['date_from'])) {
            $query->where('opened_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('opened_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay());
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query->orderByDesc('opened_at')->orderByDesc('id')->paginate($perPage);
    }

    public function active(?int $branchId = null, ?int $userId = null): ?CashierShift
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $resolvedUserId = $userId ?? Auth::guard('merchant')->id() ?? Auth::id();
        if (! $resolvedUserId) {
            return null;
        }

        $query = CashierShift::query()
            ->where('merchant_id', $merchantId)
            ->where('user_id', $resolvedUserId)
            ->where('status', CashierShiftStatus::Open->value)
            ->with(['branch:id,name', 'user:id,name', 'movements.creator:id,name']);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->orderByDesc('opened_at')->first();
    }

    public function open(array $data): CashierShift
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $userId = (int) ($data['user_id'] ?? Auth::guard('merchant')->id() ?? Auth::id());
        if ($userId <= 0) {
            abort(401, 'Pengguna tidak terautentikasi.');
        }

        $branchId = (int) $data['branch_id'];
        $branchExists = DB::table('branches')
            ->where('id', $branchId)
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $branchExists) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang tidak ditemukan atau tidak aktif.',
            ]);
        }

        return DB::transaction(function () use ($merchantId, $branchId, $userId, $data) {
            $existing = CashierShift::query()
                ->where('merchant_id', $merchantId)
                ->where('branch_id', $branchId)
                ->where('user_id', $userId)
                ->where('status', CashierShiftStatus::Open->value)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'branch_id' => "Anda masih memiliki shift aktif ({$existing->shift_number}) pada cabang ini. Tutup shift sebelumnya terlebih dahulu.",
                ]);
            }

            $shift = CashierShift::query()->create([
                'merchant_id' => $merchantId,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'shift_number' => $this->numberService->nextShiftNumber(),
                'status' => CashierShiftStatus::Open->value,
                'opening_cash' => round((float) ($data['opening_cash'] ?? 0), 2),
                'opened_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            return $shift->load(['branch:id,name', 'user:id,name', 'movements.creator:id,name']);
        });
    }

    public function show(int $shiftId): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $shift = CashierShift::query()
            ->where('merchant_id', $merchantId)
            ->with(['branch:id,name', 'user:id,name', 'closedByUser:id,name', 'movements.creator:id,name'])
            ->findOrFail($shiftId);

        return [
            'shift' => $shift,
            'summary' => $this->computeSummary($shift),
        ];
    }

    public function addMovement(int $shiftId, array $data): CashierShiftMovement
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $userId = Auth::guard('merchant')->id() ?? Auth::id();

        return DB::transaction(function () use ($merchantId, $shiftId, $data, $userId) {
            $shift = CashierShift::query()
                ->where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->findOrFail($shiftId);

            $this->assertCanOperateShift($shift);

            if ($shift->status !== CashierShiftStatus::Open) {
                throw ValidationException::withMessages([
                    'shift' => 'Tidak dapat mencatat mutasi kas pada shift yang sudah ditutup.',
                ]);
            }

            $movement = CashierShiftMovement::query()->create([
                'merchant_id' => $merchantId,
                'cashier_shift_id' => $shift->id,
                'type' => (string) $data['type'],
                'amount' => round((float) $data['amount'], 2),
                'reason' => trim((string) $data['reason']),
                'created_by' => $userId,
            ]);

            return $movement->load('creator:id,name');
        });
    }

    public function close(int $shiftId, array $data): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $userId = Auth::guard('merchant')->id() ?? Auth::id();

        return DB::transaction(function () use ($merchantId, $shiftId, $data, $userId) {
            $shift = CashierShift::query()
                ->where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->findOrFail($shiftId);

            $this->assertCanOperateShift($shift);

            if ($shift->status !== CashierShiftStatus::Open) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift kasir ini sudah ditutup sebelumnya.',
                ]);
            }

            $summary = $this->computeSummary($shift);
            $expectedCash = (float) $summary['expected_cash'];
            $actualCash = round((float) $data['actual_cash'], 2);
            $variance = round($actualCash - $expectedCash, 2);

            $shift->update([
                'status' => CashierShiftStatus::Closed->value,
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash,
                'variance' => $variance,
                'closed_at' => now(),
                'closed_by' => $userId,
                'notes' => array_key_exists('notes', $data) && $data['notes'] !== null
                    ? $data['notes']
                    : $shift->notes,
            ]);

            $shift->load(['branch:id,name', 'user:id,name', 'closedByUser:id,name', 'movements.creator:id,name']);

            return [
                'shift' => $shift,
                'summary' => $summary,
            ];
        });
    }

    /**
     * Hitung ringkasan kas & penjualan untuk suatu shift kasir.
     *
     * @return array<string, mixed>
     */
    public function computeSummary(CashierShift $shift): array
    {
        $merchantId = (int) $shift->merchant_id;
        $completedStatus = SalesTransactionStatus::Completed->value;
        $voidedStatus = SalesTransactionStatus::Voided->value;

        $hasServiceChargeCol = Schema::hasColumn('sales_transactions', 'service_charge_amount');
        $txQuery = DB::table('sales_transactions')
            ->where('merchant_id', $merchantId)
            ->where('cashier_shift_id', $shift->id)
            ->whereIn('status', [$completedStatus, $voidedStatus])
            ->whereNull('deleted_at')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as tx_count', [$completedStatus])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as voided_count', [$voidedStatus])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN subtotal ELSE 0 END), 0) as gross_sales', [$completedStatus])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN discount_amount ELSE 0 END), 0) as total_discount', [$completedStatus])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN tax_amount ELSE 0 END), 0) as total_tax', [$completedStatus])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN grand_total ELSE 0 END), 0) as net_sales', [$completedStatus])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN change_amount ELSE 0 END), 0) as total_change', [$completedStatus])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN total_cogs ELSE 0 END), 0) as total_cogs', [$completedStatus]);

        if ($hasServiceChargeCol) {
            $txQuery->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN service_charge_amount ELSE 0 END), 0) as total_service_charge', [$completedStatus]);
        }

        $txStats = $txQuery->first();

        $voidedCount = (int) ($txStats->voided_count ?? 0);

        $paymentRows = DB::table('sales_payments')
            ->join('sales_transactions', 'sales_transactions.id', '=', 'sales_payments.sales_transaction_id')
            ->where('sales_payments.merchant_id', $merchantId)
            ->where('sales_transactions.cashier_shift_id', $shift->id)
            ->where('sales_transactions.status', $completedStatus)
            ->whereNull('sales_transactions.deleted_at')
            ->groupBy('sales_payments.payment_method')
            ->selectRaw('sales_payments.payment_method, COALESCE(SUM(sales_payments.amount), 0) as total_amount, COUNT(*) as payment_count')
            ->get();

        $totalChange = round((float) ($txStats->total_change ?? 0), 2);
        $cashReceived = 0.0;
        $nonCashSales = 0.0;
        $paymentBreakdown = [];

        foreach ($paymentRows as $row) {
            $method = SalesPaymentMethod::tryFrom((string) $row->payment_method);
            $grossAmount = round((float) $row->total_amount, 2);
            $netAmount = $method === SalesPaymentMethod::Cash
                ? round(max(0.0, $grossAmount - $totalChange), 2)
                : $grossAmount;

            if ($method === SalesPaymentMethod::Cash) {
                $cashReceived += $grossAmount;
            } else {
                $nonCashSales += $grossAmount;
            }

            $paymentBreakdown[] = [
                'payment_method' => (string) $row->payment_method,
                'label' => $method?->label() ?? (string) $row->payment_method,
                'count' => (int) $row->payment_count,
                'gross_amount' => $grossAmount,
                'net_amount' => $netAmount,
            ];
        }

        $cashSalesNet = round(max(0.0, $cashReceived - $totalChange), 2);

        $movementStats = DB::table('cashier_shift_movements')
            ->where('merchant_id', $merchantId)
            ->where('cashier_shift_id', $shift->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as total_cash_in', [CashMovementType::CashIn->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as total_cash_out', [CashMovementType::CashOut->value])
            ->first();

        $cashIn = round((float) ($movementStats->total_cash_in ?? 0), 2);
        $cashOut = round((float) ($movementStats->total_cash_out ?? 0), 2);

        $cashRefunds = 0.0;
        $cashExchangeIn = 0.0;
        if (Schema::hasTable('sales_returns')) {
            if (Schema::hasColumn('sales_returns', 'settlement_amount')) {
                $returnCashStats = DB::table('sales_returns')
                    ->where('merchant_id', $merchantId)
                    ->where('cashier_shift_id', $shift->id)
                    ->where('status', SalesReturnStatus::Completed->value)
                    ->where('refund_method', SalesPaymentMethod::Cash->value)
                    ->whereNull('deleted_at')
                    ->selectRaw('COALESCE(SUM(CASE WHEN settlement_amount < 0 THEN -settlement_amount ELSE 0 END), 0) as cash_out_refund')
                    ->selectRaw('COALESCE(SUM(CASE WHEN settlement_amount > 0 THEN settlement_amount ELSE 0 END), 0) as cash_in_exchange')
                    ->first();

                $cashRefunds = round((float) ($returnCashStats->cash_out_refund ?? 0), 2);
                $cashExchangeIn = round((float) ($returnCashStats->cash_in_exchange ?? 0), 2);
            } else {
                $cashRefunds = round((float) DB::table('sales_returns')
                    ->where('merchant_id', $merchantId)
                    ->where('cashier_shift_id', $shift->id)
                    ->where('status', SalesReturnStatus::Completed->value)
                    ->where('refund_method', SalesPaymentMethod::Cash->value)
                    ->whereNull('deleted_at')
                    ->sum('total_refund'), 2);
            }
        }

        $openingCash = round((float) $shift->opening_cash, 2);
        $expectedCash = round($openingCash + $cashSalesNet + $cashIn + $cashExchangeIn - $cashOut - $cashRefunds, 2);

        $channelBreakdown = [];
        if (Schema::hasColumn('sales_transactions', 'order_type')) {
            $hasSalesChannel = Schema::hasColumn('sales_transactions', 'sales_channel');
            $selectCols = $hasSalesChannel
                ? ['order_type', 'sales_channel', DB::raw('COUNT(*) as tx_count'), DB::raw('COALESCE(SUM(grand_total), 0) as total_amount')]
                : ['order_type', DB::raw('COUNT(*) as tx_count'), DB::raw('COALESCE(SUM(grand_total), 0) as total_amount')];
            $groupCols = $hasSalesChannel ? ['order_type', 'sales_channel'] : ['order_type'];

            $channelBreakdown = DB::table('sales_transactions')
                ->where('merchant_id', $merchantId)
                ->where('cashier_shift_id', $shift->id)
                ->where('status', SalesTransactionStatus::Completed->value)
                ->whereNull('deleted_at')
                ->select($selectCols)
                ->groupBy($groupCols)
                ->orderByDesc('total_amount')
                ->get()
                ->map(function ($row) use ($hasSalesChannel) {
                    $typeVal = (string) ($row->order_type ?? 'retail');
                    $typeLabel = \Modules\Sales\Enums\SalesOrderType::tryFrom($typeVal)?->label() ?? $typeVal;
                    $channel = $hasSalesChannel && ! empty($row->sales_channel) ? (string) $row->sales_channel : null;

                    return [
                        'order_type' => $typeVal,
                        'sales_channel' => $channel,
                        'label' => $channel ? "{$typeLabel} · {$channel}" : $typeLabel,
                        'count' => (int) $row->tx_count,
                        'total' => round((float) $row->total_amount, 2),
                    ];
                })
                ->values()
                ->all();
        }

        return [
            'completed_transactions' => (int) ($txStats->tx_count ?? 0),
            'voided_transactions' => $voidedCount,
            'gross_sales' => round((float) ($txStats->gross_sales ?? 0), 2),
            'total_discount' => round((float) ($txStats->total_discount ?? 0), 2),
            'total_service_charge' => round((float) ($txStats->total_service_charge ?? 0), 2),
            'total_tax' => round((float) ($txStats->total_tax ?? 0), 2),
            'net_sales' => round((float) ($txStats->net_sales ?? 0), 2),
            'total_cogs' => round((float) ($txStats->total_cogs ?? 0), 2),
            'gross_profit' => round((float) ($txStats->net_sales ?? 0) - (float) ($txStats->total_cogs ?? 0), 2),
            'opening_cash' => $openingCash,
            'cash_sales_net' => $cashSalesNet,
            'non_cash_sales' => round($nonCashSales, 2),
            'total_cash_in' => $cashIn,
            'total_cash_out' => $cashOut,
            'cash_refunds' => $cashRefunds,
            'cash_exchange_in' => $cashExchangeIn,
            'expected_cash' => $expectedCash,
            'payment_breakdown' => $paymentBreakdown,
            'channel_breakdown' => $channelBreakdown,
        ];
    }

    /**
     * Cegah IDOR antar-kasir: hanya pemilik shift, owner, atau pemegang izin sales.shift.manage yang boleh mengubah/menutup shift.
     */
    private function assertCanOperateShift(CashierShift $shift): void
    {
        $user = Auth::guard('merchant')->user();
        if (! $user) {
            return;
        }

        if ((int) $shift->user_id === (int) $user->getAuthIdentifier()) {
            return;
        }

        $canManageAny = (method_exists($user, 'hasRole') && $user->hasRole('owner'))
            || (method_exists($user, 'can') && $user->can('sales.shift.manage'));

        abort_unless($canManageAny, 403, 'Anda tidak memiliki izin untuk mengakses shift kasir milik pengguna lain.');
    }
}
