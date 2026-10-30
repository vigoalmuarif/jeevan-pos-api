<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\ExtPurchaseRequest\Http\Requests\CancelPurchaseRequestRequest;
use Modules\ExtPurchaseRequest\Http\Requests\ConvertPurchaseRequestToOrdersRequest;
use Modules\ExtPurchaseRequest\Http\Requests\RejectPurchaseRequestRequest;
use Modules\ExtPurchaseRequest\Http\Requests\StorePurchaseRequestRequest;
use Modules\ExtPurchaseRequest\Services\PurchaseRequestService;
use Modules\ExtPurchaseRequest\Transformers\PurchaseRequestListResource;
use Modules\ExtPurchaseRequest\Transformers\PurchaseRequestResource;
use Modules\Purchase\Transformers\PurchaseOrderListResource;

class PurchaseRequestController extends Controller
{
    public function __construct(
        private readonly PurchaseRequestService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->query('per_page', 15);
        $filters = [
            'status' => $request->query('status'),
            'warehouse_id' => $request->query('warehouse_id'),
            'branch_id' => $request->query('branch_id'),
            'search' => $request->query('search'),
        ];

        return PurchaseRequestListResource::collection($this->service->getPaginated($filters, $perPage));
    }

    public function options(): JsonResponse
    {
        return response()->json($this->service->getOptions());
    }

    public function approvedItems(Request $request): JsonResponse
    {
        $items = $this->service->getApprovedItems([
            'purchase_request_id' => $request->query('purchase_request_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'branch_id' => $request->query('branch_id'),
            'product_variant_id' => $request->query('product_variant_id'),
        ]);

        return response()->json([
            'data' => $items->map(fn ($item) => [
                'purchase_request_item_id' => $item->id,
                'purchase_request_id' => $item->purchase_request_id,
                'document_number' => $item->purchaseRequest?->document_number,
                'request_date' => $item->purchaseRequest?->request_date?->format('Y-m-d'),
                'required_date' => $item->purchaseRequest?->required_date?->format('Y-m-d'),
                'warehouse_id' => $item->purchaseRequest?->warehouse_id,
                'warehouse_name' => $item->purchaseRequest?->warehouse?->name,
                'branch_id' => $item->purchaseRequest?->branch_id,
                'branch_name' => $item->purchaseRequest?->branch?->name,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->productVariant?->product?->name,
                'variant_name' => $item->productVariant?->name,
                'sku' => $item->productVariant?->sku,
                'unit_id' => $item->unit_id,
                'unit_name' => $item->unit?->name,
                'unit_code' => $item->unit?->code,
                'conversion_rate' => (string) $item->conversion_rate,
                'base_quantity' => (string) $item->base_quantity,
                'ordered_base_quantity' => (string) $item->ordered_base_quantity,
                'remaining_base_quantity' => (string) $item->remaining_base_quantity,
                'suggested_supplier_id' => $item->suggested_supplier_id,
                'suggested_supplier_name' => $item->suggestedSupplier?->name,
                'estimated_unit_price' => $item->estimated_unit_price !== null ? (string) $item->estimated_unit_price : null,
                'notes' => $item->notes,
            ]),
        ]);
    }

    public function approvedItemOptions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->getApprovedItemOptions(
                (string) $request->query('type', ''),
                (string) $request->query('search', ''),
            ),
        ]);
    }

    public function show(int $id): PurchaseRequestResource
    {
        return new PurchaseRequestResource($this->service->find($id));
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $pr = $this->service->create($validated, $validated['items']);

        return (new PurchaseRequestResource($this->service->find($pr->id)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StorePurchaseRequestRequest $request, int $id): PurchaseRequestResource
    {
        $validated = $request->validated();
        $this->service->update($id, $validated, $validated['items']);

        return new PurchaseRequestResource($this->service->find($id));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json(['message' => 'Purchase Request berhasil dihapus.'], 200);
    }

    public function submit(int $id): PurchaseRequestResource
    {
        $pr = $this->service->submit($id);

        return new PurchaseRequestResource($this->service->find($pr->id));
    }

    public function approve(int $id): PurchaseRequestResource
    {
        $pr = $this->service->approve($id);

        return new PurchaseRequestResource($this->service->find($pr->id));
    }

    public function reject(RejectPurchaseRequestRequest $request, int $id): PurchaseRequestResource
    {
        $pr = $this->service->reject($id, $request->validated()['reason']);

        return new PurchaseRequestResource($this->service->find($pr->id));
    }

    public function cancel(CancelPurchaseRequestRequest $request, int $id): PurchaseRequestResource
    {
        $pr = $this->service->cancel($id, $request->validated()['reason']);

        return new PurchaseRequestResource($this->service->find($pr->id));
    }

    public function close(int $id): PurchaseRequestResource
    {
        $pr = $this->service->close($id);

        return new PurchaseRequestResource($this->service->find($pr->id));
    }

    public function convertToOrders(ConvertPurchaseRequestToOrdersRequest $request): JsonResponse
    {
        $orders = $this->service->convertToOrders($request->validated()['groups']);

        return response()->json([
            'message' => count($orders).' Purchase Order berhasil dibuat dari Purchase Request.',
            'data' => PurchaseOrderListResource::collection($orders),
        ], 201);
    }
}
