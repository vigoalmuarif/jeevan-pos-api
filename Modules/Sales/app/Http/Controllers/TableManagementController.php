<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Sales\Enums\SalesPaymentMethod;
use Modules\Sales\Http\Requests\SaveTableOrderRequest;
use Modules\Sales\Http\Requests\StoreDiningAreaRequest;
use Modules\Sales\Http\Requests\StoreDiningTableRequest;
use Modules\Sales\Services\TableManagementService;
use Modules\Sales\Transformers\SalesTransactionResource;

class TableManagementController extends Controller
{
    public function __construct(
        private readonly TableManagementService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->service->listByBranch((int) $validated['branch_id']),
        ]);
    }

    public function storeArea(StoreDiningAreaRequest $request): JsonResponse
    {
        $area = $this->service->createArea($request->validated());

        return response()->json([
            'data' => $area,
            'message' => 'Area meja berhasil ditambahkan.',
        ], 201);
    }

    public function updateArea(Request $request, int $area): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->service->updateArea($area, $validated),
            'message' => 'Area meja berhasil diperbarui.',
        ]);
    }

    public function destroyArea(int $area): JsonResponse
    {
        $this->service->deleteArea($area);

        return response()->json([
            'message' => 'Area meja berhasil dihapus.',
        ]);
    }

    public function storeTable(StoreDiningTableRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->createTable($request->validated()),
            'message' => 'Meja berhasil ditambahkan.',
        ], 201);
    }

    public function updateTable(Request $request, int $table): JsonResponse
    {
        $validated = $request->validate([
            'dining_area_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['sometimes', 'required', 'string', 'max:30'],
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'shape' => ['nullable', 'string', Rule::in(['square', 'round', 'rectangle'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->service->updateTable($table, $validated),
            'message' => 'Meja berhasil diperbarui.',
        ]);
    }

    public function updateStatus(Request $request, int $table): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['available', 'occupied', 'reserved', 'cleaning'])],
        ]);

        return response()->json([
            'data' => $this->service->updateStatus($table, (string) $validated['status']),
            'message' => 'Status meja berhasil diperbarui.',
        ]);
    }

    public function destroyTable(int $table): JsonResponse
    {
        $this->service->deleteTable($table);

        return response()->json([
            'message' => 'Meja berhasil dihapus.',
        ]);
    }

    public function saveOrder(SaveTableOrderRequest $request, int $table): JsonResponse
    {
        $result = $this->service->saveTableOrder($table, $request->validated());

        return response()->json([
            'data' => [
                'table' => $result['table'],
                'transaction' => new SalesTransactionResource($result['transaction']),
            ],
            'message' => 'Pesanan meja berhasil disimpan.',
        ]);
    }

    public function fireCourse(Request $request, int $table): JsonResponse
    {
        $validated = $request->validate([
            'course_number' => ['nullable', 'integer', 'min:1', 'max:3'],
        ]);

        $courseNumber = isset($validated['course_number']) ? (int) $validated['course_number'] : null;
        $result = $this->service->fireCourse($table, $courseNumber);

        return response()->json([
            'data' => [
                'table' => $result['table'],
                'transaction' => new SalesTransactionResource($result['transaction']),
                'fired_count' => $result['fired_count'],
            ],
            'message' => $courseNumber
                ? "Course {$courseNumber} berhasil dikirim ke dapur."
                : 'Semua item tertahan berhasil dikirim ke dapur.',
        ]);
    }

    public function moveTable(Request $request, int $table): JsonResponse
    {
        $validated = $request->validate([
            'target_table_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->service->moveTable($table, (int) $validated['target_table_id']),
            'message' => 'Meja berhasil dipindahkan.',
        ]);
    }

    public function mergeTables(Request $request, int $table): JsonResponse
    {
        $validated = $request->validate([
            'target_table_id' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->service->mergeTables($table, (int) $validated['target_table_id']);

        return response()->json([
            'data' => [
                'source_table' => $result['source_table'],
                'target_table' => $result['target_table'],
                'transaction' => new SalesTransactionResource($result['transaction']),
            ],
            'message' => 'Tagihan meja berhasil digabungkan.',
        ]);
    }

    public function splitBill(Request $request, int $table): JsonResponse
    {
        $paymentMethods = array_column(SalesPaymentMethod::cases(), 'value');
        $validated = $request->validate([
            'cashier_shift_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'min:1'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method' => ['required', 'string', Rule::in($paymentMethods)],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'payments.*.reference_number' => ['nullable', 'string', 'max:100'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');
        $result = $this->service->splitBill($table, $validated, is_string($idempotencyKey) ? $idempotencyKey : null);

        return response()->json([
            'data' => [
                'paid_transaction' => new SalesTransactionResource($result['paid_transaction']),
                'remaining_transaction' => $result['remaining_transaction']
                    ? new SalesTransactionResource($result['remaining_transaction'])
                    : null,
                'table' => $result['table'],
            ],
            'message' => 'Split bill berhasil diproses.',
        ]);
    }
}
