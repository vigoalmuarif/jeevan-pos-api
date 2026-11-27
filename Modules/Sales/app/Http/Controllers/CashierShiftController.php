<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Http\Requests\CloseCashierShiftRequest;
use Modules\Sales\Http\Requests\ListCashierShiftsRequest;
use Modules\Sales\Http\Requests\OpenCashierShiftRequest;
use Modules\Sales\Http\Requests\StoreCashMovementRequest;
use Modules\Sales\Services\CashierShiftService;
use Modules\Sales\Transformers\CashierShiftMovementResource;
use Modules\Sales\Transformers\CashierShiftResource;

class CashierShiftController extends Controller
{
    public function __construct(
        private readonly CashierShiftService $shiftService,
    ) {}

    public function index(ListCashierShiftsRequest $request): JsonResponse
    {
        $paginator = $this->shiftService->list($request->validated());

        return response()->json([
            'data' => CashierShiftResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function active(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        $shift = $this->shiftService->active($branchId);

        if (! $shift) {
            return response()->json([
                'data' => null,
                'summary' => null,
            ]);
        }

        return response()->json([
            'data' => new CashierShiftResource($shift),
            'summary' => $this->shiftService->computeSummary($shift),
        ]);
    }

    public function open(OpenCashierShiftRequest $request): JsonResponse
    {
        $shift = $this->shiftService->open($request->validated());

        return response()->json([
            'message' => 'Shift kasir berhasil dibuka.',
            'data' => new CashierShiftResource($shift),
            'summary' => $this->shiftService->computeSummary($shift),
        ], 201);
    }

    public function show(int $shift): JsonResponse
    {
        $result = $this->shiftService->show($shift);

        return response()->json([
            'data' => new CashierShiftResource($result['shift']),
            'summary' => $result['summary'],
        ]);
    }

    public function addMovement(StoreCashMovementRequest $request, int $shift): JsonResponse
    {
        $movement = $this->shiftService->addMovement($shift, $request->validated());

        return response()->json([
            'message' => 'Mutasi kas laci berhasil dicatat.',
            'data' => new CashierShiftMovementResource($movement),
        ], 201);
    }

    public function close(CloseCashierShiftRequest $request, int $shift): JsonResponse
    {
        $result = $this->shiftService->close($shift, $request->validated());

        return response()->json([
            'message' => 'Shift kasir berhasil ditutup.',
            'data' => new CashierShiftResource($result['shift']),
            'summary' => $result['summary'],
        ]);
    }
}
