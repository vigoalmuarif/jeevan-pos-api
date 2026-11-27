<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Http\Requests\CompleteHeldTransactionRequest;
use Modules\Sales\Http\Requests\ListSalesTransactionsRequest;
use Modules\Sales\Http\Requests\StoreSalesTransactionRequest;
use Modules\Sales\Http\Requests\VoidSalesTransactionRequest;
use Modules\Sales\Services\SalesTransactionService;
use Modules\Sales\Transformers\SalesTransactionResource;

class SalesTransactionController extends Controller
{
    public function __construct(
        private readonly SalesTransactionService $transactionService,
    ) {}

    public function index(ListSalesTransactionsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $this->transactionService->list($filters);

        return response()->json([
            'data' => SalesTransactionResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'channel_summary' => $this->transactionService->channelSummary($filters),
        ]);
    }

    public function held(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        $transactions = $this->transactionService->listHeld($branchId);

        return response()->json([
            'data' => SalesTransactionResource::collection($transactions),
        ]);
    }

    public function show(int $transaction): JsonResponse
    {
        $model = $this->transactionService->show($transaction);

        return response()->json([
            'data' => new SalesTransactionResource($model),
        ]);
    }

    public function store(StoreSalesTransactionRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $model = $this->transactionService->create(
            $request->validated(),
            is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        $message = $model->status->value === 'held'
            ? 'Transaksi berhasil ditahan (Hold Bill).'
            : 'Transaksi penjualan berhasil diselesaikan.';

        return response()->json([
            'message' => $message,
            'data' => new SalesTransactionResource($model),
        ], 201);
    }

    public function completeHeld(CompleteHeldTransactionRequest $request, int $transaction): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $model = $this->transactionService->completeHeld(
            $transaction,
            $request->validated(),
            is_string($idempotencyKey) ? $idempotencyKey : null,
        );

        return response()->json([
            'message' => 'Transaksi tertahan berhasil diselesaikan.',
            'data' => new SalesTransactionResource($model),
        ]);
    }

    public function destroyHeld(int $transaction): JsonResponse
    {
        $this->transactionService->deleteHeld($transaction);

        return response()->json([
            'message' => 'Bill tertahan berhasil dihapus.',
        ]);
    }

    public function void(VoidSalesTransactionRequest $request, int $transaction): JsonResponse
    {
        $model = $this->transactionService->void(
            $transaction,
            (string) $request->validated('reason'),
        );

        return response()->json([
            'message' => 'Transaksi berhasil dibatalkan (void) dan stok telah dikembalikan.',
            'data' => new SalesTransactionResource($model),
        ]);
    }
}
