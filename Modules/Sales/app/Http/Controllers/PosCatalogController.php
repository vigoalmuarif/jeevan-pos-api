<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Http\Requests\PosCatalogRequest;
use Modules\Sales\Http\Requests\PosCustomerSearchRequest;
use Modules\Sales\Http\Requests\PosStocksRequest;
use Modules\Sales\Http\Requests\ResolvePosPriceRequest;
use Modules\Sales\Http\Requests\ToggleSoldOutRequest;
use Modules\Sales\Services\PosCatalogService;

class PosCatalogController extends Controller
{
    public function __construct(
        private readonly PosCatalogService $catalogService,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $branchId = $request->filled('branch_id') ? (int) $request->query('branch_id') : null;

        return response()->json([
            'data' => $this->catalogService->options($branchId),
        ]);
    }

    public function catalog(PosCatalogRequest $request): JsonResponse
    {
        return response()->json(
            $this->catalogService->catalog($request->validated())
        );
    }

    public function stocks(PosStocksRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => $this->catalogService->stocks(
                branchId: (int) $validated['branch_id'],
                variantIds: array_map('intval', $validated['variant_ids']),
            ),
        ]);
    }

    public function customers(PosCustomerSearchRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogService->searchCustomers($request->validated()),
        ]);
    }

    public function resolvePrice(ResolvePosPriceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->catalogService->resolvePrice(
            variantUnitId: (int) $validated['product_variant_unit_id'],
            quantity: (float) $validated['quantity'],
            priceListId: isset($validated['price_list_id']) ? (int) $validated['price_list_id'] : null,
            branchId: isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
        );

        return response()->json([
            'data' => $result,
        ]);
    }

    public function soldOutMenu(Request $request): JsonResponse
    {
        $branchId = (int) $request->query('branch_id', 0);
        abort_if($branchId <= 0, 422, 'branch_id wajib diisi.');

        return response()->json([
            'data' => $this->catalogService->listSoldOutMenu(
                branchId: $branchId,
                search: $request->query('search'),
                onlySoldOut: filter_var($request->query('only_sold_out', false), FILTER_VALIDATE_BOOLEAN),
            ),
        ]);
    }

    public function toggleSoldOut(ToggleSoldOutRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->catalogService->toggleSoldOut(
            branchId: (int) $validated['branch_id'],
            productVariantId: (int) $validated['product_variant_id'],
            isSoldOut: (bool) $validated['is_sold_out'],
            reason: $validated['reason'] ?? null,
        );

        return response()->json([
            'data' => $result,
            'message' => $result['is_sold_out']
                ? 'Menu ditandai Habis Hari Ini (86).'
                : 'Menu ditandai Tersedia kembali.',
        ]);
    }
}
