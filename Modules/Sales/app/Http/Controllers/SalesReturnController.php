<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Http\Requests\PosCatalogRequest;
use Modules\Sales\Http\Requests\StoreSalesReturnRequest;
use Modules\Sales\Http\Requests\VoidSalesReturnRequest;
use Modules\Sales\Services\PosCatalogService;
use Modules\Sales\Services\SalesReturnService;
use Modules\Sales\Transformers\SalesReturnResource;

class SalesReturnController extends Controller
{
    public function __construct(
        private readonly SalesReturnService $returnService,
        private readonly PosCatalogService $catalogService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->returnService->list($request->all());

        return response()->json([
            'data' => SalesReturnResource::collection($paginator->getCollection())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function returnableTransactions(Request $request): JsonResponse
    {
        $data = $this->returnService->returnableTransactions($request->only(['search', 'branch_id', 'limit']));

        return response()->json([
            'data' => $data,
        ]);
    }

    public function exchangeProducts(PosCatalogRequest $request): JsonResponse
    {
        return response()->json(
            $this->catalogService->catalog($request->validated())
        );
    }

    public function show(int $salesReturn): JsonResponse
    {
        $record = $this->returnService->show($salesReturn);

        return response()->json([
            'data' => (new SalesReturnResource($record))->resolve(),
        ]);
    }

    public function store(StoreSalesReturnRequest $request): JsonResponse
    {
        $record = $this->returnService->create($request->validated());

        return response()->json([
            'data' => (new SalesReturnResource($record))->resolve(),
            'message' => 'Retur penjualan berhasil dicatat.',
        ], 201);
    }

    public function void(VoidSalesReturnRequest $request, int $salesReturn): JsonResponse
    {
        $record = $this->returnService->void($salesReturn, (string) $request->validated('reason'));

        return response()->json([
            'data' => (new SalesReturnResource($record))->resolve(),
            'message' => 'Retur penjualan berhasil dibatalkan.',
        ]);
    }
}
