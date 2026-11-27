<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Sales\Services\KitchenDisplayService;
use Modules\Sales\Services\SalesTransactionService;

class KitchenDisplayController extends Controller
{
    public function __construct(
        private readonly KitchenDisplayService $kdsService,
        private readonly SalesTransactionService $txService,
    ) {}

    public function stations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->kdsService->listStations((int) $validated['branch_id']),
        ]);
    }

    public function storeStation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->kdsService->createStation($validated),
            'message' => 'Station dapur berhasil ditambahkan.',
        ], 201);
    }

    public function updateStation(Request $request, int $station): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:30'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->kdsService->updateStation($station, $validated),
            'message' => 'Station dapur berhasil diperbarui.',
        ]);
    }

    public function destroyStation(int $station): JsonResponse
    {
        $this->kdsService->deleteStation($station);

        return response()->json([
            'message' => 'Station dapur berhasil dihapus.',
        ]);
    }

    public function tickets(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'station_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'preparing', 'ready', 'served', 'voided'])],
        ]);

        return response()->json([
            'data' => $this->kdsService->listTickets(
                branchId: (int) $validated['branch_id'],
                stationId: isset($validated['station_id']) ? (int) $validated['station_id'] : null,
                status: $validated['status'] ?? null,
            ),
        ]);
    }

    public function updateTicketStatus(Request $request, int $ticket): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['pending', 'preparing', 'ready', 'served'])],
        ]);

        return response()->json([
            'data' => $this->kdsService->updateTicketStatus($ticket, (string) $validated['status']),
            'message' => 'Status pesanan dapur diperbarui.',
        ]);
    }

    public function dispatchTransaction(int $transaction): JsonResponse
    {
        $tx = $this->txService->show($transaction);
        $tickets = $this->kdsService->dispatchForTransaction($tx, incremental: true, bypassEntitlementCheck: true);

        return response()->json([
            'data' => $tickets,
            'message' => count($tickets) > 0
                ? 'Tiket dapur berhasil dikirim.'
                : 'Tidak ada item baru yang perlu dikirim ke dapur.',
        ]);
    }
}
