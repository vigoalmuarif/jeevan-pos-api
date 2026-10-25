<?php

namespace Modules\Purchase\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Purchase\Http\Requests\StorePurchasePaymentRequest;
use Modules\Purchase\Http\Requests\VoidPurchaseDocumentRequest;
use Modules\Purchase\Services\PurchasePaymentService;
use Modules\Purchase\Transformers\PayablePurchaseInvoiceResource;
use Modules\Purchase\Transformers\PurchasePaymentResource;

class PurchasePaymentController extends Controller
{
    public function __construct(private readonly PurchasePaymentService $service)
    {
    }

    public function index(Request $request)
    {
        return PurchasePaymentResource::collection($this->service->getPaginated($request));
    }

    public function show(int $id)
    {
        return new PurchasePaymentResource($this->service->findForCurrentMerchant($id));
    }

    /** Faktur yang masih dapat dibayar untuk satu supplier (bahan form pembayaran). */
    public function payableInvoices(Request $request)
    {
        $supplierId = (int) $request->validate(['supplier_id' => ['required', 'integer']])['supplier_id'];

        return PayablePurchaseInvoiceResource::collection($this->service->payableInvoices($supplierId));
    }

    public function store(StorePurchasePaymentRequest $request)
    {
        $payment = $this->service->create(
            $request->safe()->except('allocations'),
            $request->validated('allocations'),
        );

        return (new PurchasePaymentResource($this->service->findForCurrentMerchant($payment->id)))
            ->response()
            ->setStatusCode(201);
    }

    public function void(VoidPurchaseDocumentRequest $request, int $id)
    {
        $this->service->void($id, $request->validated('reason'));

        return new PurchasePaymentResource($this->service->findForCurrentMerchant($id));
    }
}
