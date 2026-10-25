<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Purchase\Http\Requests\StorePurchaseInvoiceRequest;
use Modules\Purchase\Http\Requests\UpdatePurchaseInvoiceRequest;
use Modules\Purchase\Http\Requests\VoidPurchaseDocumentRequest;
use Modules\Purchase\Services\PurchaseInvoiceService;
use Modules\Purchase\Transformers\InvoiceableGoodsReceiptResource;
use Modules\Purchase\Transformers\PurchaseInvoiceResource;

class PurchaseInvoiceController extends Controller
{
    public function __construct(private readonly PurchaseInvoiceService $service)
    {
    }

    public function index(Request $request)
    {
        return PurchaseInvoiceResource::collection($this->service->getPaginated($request));
    }

    public function show(int $id)
    {
        return new PurchaseInvoiceResource($this->service->findForCurrentMerchant($id));
    }

    /** Penerimaan siap difakturkan untuk satu supplier (bahan pilihan pada form faktur). */
    public function invoiceable(Request $request)
    {
        $supplierId = (int) $request->validate(['supplier_id' => ['required', 'integer']])['supplier_id'];

        return InvoiceableGoodsReceiptResource::collection($this->service->invoiceableReceipts($supplierId));
    }

    public function store(StorePurchaseInvoiceRequest $request)
    {
        $invoice = $this->service->create(
            $request->safe()->except('goods_receipt_ids'),
            $request->validated('goods_receipt_ids'),
        );

        return (new PurchaseInvoiceResource($this->service->findForCurrentMerchant($invoice->id)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePurchaseInvoiceRequest $request, int $id)
    {
        $this->service->update($id, $request->validated());

        return new PurchaseInvoiceResource($this->service->findForCurrentMerchant($id));
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);

        return response()->json(null, 204);
    }

    public function post(int $id)
    {
        $this->service->post($id);

        return new PurchaseInvoiceResource($this->service->findForCurrentMerchant($id));
    }

    public function void(VoidPurchaseDocumentRequest $request, int $id)
    {
        $this->service->void($id, $request->validated('reason'));

        return new PurchaseInvoiceResource($this->service->findForCurrentMerchant($id));
    }
}
