<?php

namespace Modules\Purchase\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Purchase\Enums\DocumentStatus;
use Modules\Purchase\Enums\PaymentStatus;
use Modules\Purchase\Exceptions\PurchaseRuleException;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Models\PurchasePayment;
use Modules\Purchase\Support\Numeric;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Pembayaran ke supplier dengan alokasi ke faktur (pelunasan bertahap).
 *
 * Format input (sudah tervalidasi Form Request):
 *   $data        : supplier_id, payment_date, payment_method, reference_number?, notes?, amount? (opsional)
 *   $allocations : list of [purchase_invoice_id, amount]
 *
 * Aturan inti:
 *  - Pembayaran langsung berstatus posted (tidak ada draft) dan TIDAK dapat diubah/dihapus;
 *    kesalahan dikoreksi dengan void lalu membuat pembayaran baru.
 *  - Seluruh nominal wajib teralokasi; semua faktur milik supplier yang sama, sudah diposting,
 *    belum lunas, dan nominal alokasi tidak boleh melebihi SISA hutang faktur.
 *  - Sisa hutang dibaca dari baris faktur yang SUDAH DIKUNCI (lockForUpdate), sehingga dua
 *    pembayaran bersamaan tidak bisa sama-sama memakai sisa yang sama.
 *  - paid_amount dan payment_status faktur diperbarui di transaksi yang sama.
 *  - void() menerima id ATAU model (mengikuti service Inventory yang berbasis id).
 */
class PurchasePaymentService
{
    private const HEADER_FIELDS = ['supplier_id', 'payment_date', 'payment_method', 'reference_number', 'notes'];

    public function __construct(
        private readonly PurchaseDocumentNumberService $numbers,
    ) {
    }

    // -------------------------------------------------------
    // Baca
    // -------------------------------------------------------

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $merchantId = $this->requireMerchant();

        $config = new DataTableConfig(
            allowedFields: [
                'id', 'document_number', 'supplier_id', 'supplier_name', 'payment_date',
                'payment_method', 'amount', 'reference_number', 'status', 'invoices_count', 'created_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('supplier_id', 'purchase_payments.supplier_id'),
                AllowedFilter::exact('status', 'purchase_payments.status'),
                AllowedFilter::exact('payment_method', 'purchase_payments.payment_method'),
                AllowedFilter::callback('payment_date_from', fn (Builder $q, mixed $v) => $q->whereDate('purchase_payments.payment_date', '>=', $v)),
                AllowedFilter::callback('payment_date_to', fn (Builder $q, mixed $v) => $q->whereDate('purchase_payments.payment_date', '<=', $v)),
            ],
            allowedSorts: [
                'purchase_payments.document_number', 'purchase_payments.payment_date',
                'purchase_payments.amount', 'suppliers.name',
            ],
            searchableColumns: ['purchase_payments.document_number', 'purchase_payments.reference_number', 'suppliers.name'],
            defaultSort: '-purchase_payments.payment_date',
        );

        // Jumlah faktur yang dilunasi, sebagai subquery agar tidak menggandakan baris pembayaran.
        $invoicesCount = DB::table('purchase_payment_allocations as count_alloc')
            ->selectRaw('count(*)')
            ->whereColumn('count_alloc.purchase_payment_id', 'purchase_payments.id')
            ->where('count_alloc.merchant_id', $merchantId);

        $query = PurchasePayment::query()
            ->join('suppliers', 'suppliers.id', '=', 'purchase_payments.supplier_id')
            ->where('purchase_payments.merchant_id', $merchantId)
            ->where('suppliers.merchant_id', $merchantId)
            ->select([
                'purchase_payments.id',
                'purchase_payments.document_number',
                'purchase_payments.supplier_id',
                'suppliers.name as supplier_name',
                'purchase_payments.payment_date',
                'purchase_payments.payment_method',
                'purchase_payments.amount',
                'purchase_payments.reference_number',
                'purchase_payments.status',
                'purchase_payments.created_at',
            ])
            ->selectSub($invoicesCount, 'invoices_count');

        return ServerSideTable::paginate($query, $config, $request);
    }

    /** Detail pembayaran beserta faktur yang dilunasi dan keadaan faktur saat ini. */
    public function findForCurrentMerchant(int $id): array
    {
        $merchantId = $this->requireMerchant();
        $payment = PurchasePayment::query()->where('merchant_id', $merchantId)->findOrFail($id);

        $supplier = DB::table('suppliers')
            ->where('merchant_id', $merchantId)
            ->where('id', $payment->supplier_id)
            ->first(['id', 'name']);

        $allocations = DB::table('purchase_payment_allocations as alloc')
            ->join('purchase_invoices as inv', 'inv.id', '=', 'alloc.purchase_invoice_id')
            ->where('alloc.merchant_id', $merchantId)
            ->where('alloc.purchase_payment_id', $payment->id)
            ->select([
                'alloc.id', 'alloc.amount',
                'inv.id as purchase_invoice_id', 'inv.document_number as invoice_number',
                'inv.supplier_invoice_number', 'inv.invoice_date', 'inv.due_date',
                'inv.grand_total', 'inv.paid_amount', 'inv.balance_amount', 'inv.payment_status',
            ])
            ->orderBy('inv.due_date')->orderBy('inv.id')
            ->get();

        return [
            'id' => $payment->id,
            'document_number' => $payment->document_number,
            'supplier_id' => $payment->supplier_id,
            'supplier_name' => $supplier?->name,
            'payment_date' => $payment->payment_date,
            'payment_method' => $payment->payment_method,
            'amount' => $payment->amount,
            'reference_number' => $payment->reference_number,
            'notes' => $payment->notes,
            'status' => $payment->status,
            'voided_at' => $payment->voided_at,
            'void_reason' => $payment->void_reason,
            'created_at' => $payment->created_at,
            'allocations' => $allocations,
        ];
    }

    /**
     * Faktur yang masih dapat dibayar untuk satu supplier (bahan form pembayaran):
     * posted dan belum lunas, urut jatuh tempo terdekat lebih dulu.
     */
    public function payableInvoices(int $supplierId): Collection
    {
        $this->requireMerchant();

        return PurchaseInvoice::query()
            ->outstanding()
            ->where('supplier_id', $supplierId)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'document_number', 'supplier_invoice_number', 'invoice_date', 'due_date',
                'grand_total', 'paid_amount', 'balance_amount', 'payment_status'])
            ->map(fn (PurchaseInvoice $i) => [
                'id' => $i->id,
                'document_number' => $i->document_number,
                'supplier_invoice_number' => $i->supplier_invoice_number,
                'invoice_date' => $i->invoice_date,
                'due_date' => $i->due_date,
                'grand_total' => $i->grand_total,
                'paid_amount' => $i->paid_amount,
                'balance_amount' => $i->balance_amount,
                'payment_status' => $i->payment_status,
                'is_overdue' => $i->due_date !== null && $i->due_date->lt(today()),
            ]);
    }

    // -------------------------------------------------------
    // Tulis
    // -------------------------------------------------------

    /**
     * @param list<array{purchase_invoice_id:int|string, amount:int|float|string}> $allocations
     */
    public function create(array $data, array $allocations): PurchasePayment
    {
        return DB::transaction(function () use ($data, $allocations) {
            $amounts = $this->normalizeAllocations($allocations);

            // Faktur dikunci berurutan id: dua pembayaran yang menyentuh faktur yang sama
            // selalu mengunci dengan urutan sama, sehingga tidak saling menunggu (deadlock).
            $invoices = PurchaseInvoice::query()
                ->whereIn('id', array_keys($amounts))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // MerchantScope: faktur milik merchant lain tidak ditemukan, sehingga ikut ditolak di sini.
            if ($invoices->count() !== count($amounts)) {
                throw new PurchaseRuleException('Ada faktur yang tidak ditemukan.');
            }

            $supplierId = (int) $data['supplier_id'];
            $total = '0.0000';

            foreach ($amounts as $invoiceId => $amount) {
                /** @var PurchaseInvoice $invoice */
                $invoice = $invoices->get($invoiceId);

                if ((int) $invoice->supplier_id !== $supplierId) {
                    throw new PurchaseRuleException('Semua faktur dalam satu pembayaran harus milik supplier yang sama.');
                }
                if (! $invoice->isPayable()) {
                    throw new PurchaseRuleException("Faktur {$invoice->document_number} tidak dapat dibayar (belum diposting atau sudah lunas).");
                }

                // Sisa dihitung dari data yang sudah terkunci (bukan dari form di browser).
                $balance = bcsub((string) $invoice->grand_total, (string) $invoice->paid_amount, 4);
                if (bccomp($amount, $balance, 4) > 0) {
                    throw new PurchaseRuleException("Nominal melebihi sisa hutang pada faktur {$invoice->document_number}.");
                }

                $total = bcadd($total, $amount, 4);
            }

            // Bila client ikut mengirim total, harus sama dengan jumlah alokasi (cegah salah ketik).
            if (isset($data['amount']) && bccomp(Numeric::string($data['amount']), $total, 4) !== 0) {
                throw new PurchaseRuleException('Total pembayaran tidak sama dengan jumlah alokasi ke faktur.');
            }

            $payment = new PurchasePayment();
            $payment->fill(array_merge(
                Arr::only($data, self::HEADER_FIELDS),
                ['amount' => $total, 'document_number' => $this->numbers->generate('purchase_payment')],
            ));
            $payment->save();

            foreach ($amounts as $invoiceId => $amount) {
                $payment->allocations()->create(['purchase_invoice_id' => $invoiceId, 'amount' => $amount]);
                $this->moveInvoicePaid($invoices->get($invoiceId), $amount, add: true);
            }

            return $payment->load('allocations');
        });
    }

    /**
     * Void pembayaran: nominal dikembalikan ke sisa hutang faktur terkait, dan status bayarnya
     * dihitung ulang. Pembayaran yang di-void tetap tersimpan untuk audit.
     */
    public function void(int|PurchasePayment $payment, string $reason): PurchasePayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $id = $payment instanceof PurchasePayment ? $payment->getKey() : $payment;
            $payment = PurchasePayment::query()->lockForUpdate()->with('allocations')->findOrFail($id);

            if (! $payment->status->canVoid()) {
                throw new PurchaseRuleException('Pembayaran ini sudah dibatalkan.');
            }

            $invoices = PurchaseInvoice::query()
                ->whereIn('id', $payment->allocations->pluck('purchase_invoice_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($payment->allocations as $allocation) {
                $this->moveInvoicePaid($invoices->get($allocation->purchase_invoice_id), (string) $allocation->amount, add: false);
            }

            $payment->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => auth('merchant')->id(),
                'void_reason' => $reason,
            ])->save();

            return $payment;
        });
    }

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    /**
     * Tambah / kurangi paid_amount faktur lalu hitung ulang payment_status (satu-satunya tempat
     * status bayar diubah). Faktur harus sudah terkunci oleh pemanggil.
     */
    private function moveInvoicePaid(PurchaseInvoice $invoice, string $amount, bool $add): void
    {
        $paid = $add
            ? bcadd((string) $invoice->paid_amount, $amount, 4)
            : bcsub((string) $invoice->paid_amount, $amount, 4);

        // Jaring pengaman; constraint database (0 <= paid <= grand_total) menjadi lapis terakhir.
        if (bccomp($paid, '0', 4) < 0 || bccomp($paid, (string) $invoice->grand_total, 4) > 0) {
            throw new PurchaseRuleException("Nominal pembayaran tidak konsisten dengan faktur {$invoice->document_number}.");
        }

        $invoice->forceFill([
            'paid_amount' => $paid,
            'payment_status' => PaymentStatus::fromAmounts($paid, (string) $invoice->grand_total),
        ])->save();
    }

    /**
     * Validasi dan gabungkan alokasi menjadi [id faktur => nominal], terurut menurut id.
     *
     * @param  list<array<string,mixed>> $allocations
     * @return array<int,string>
     */
    private function normalizeAllocations(array $allocations): array
    {
        if ($allocations === []) {
            throw new PurchaseRuleException('Pembayaran harus dialokasikan ke minimal satu faktur.');
        }

        $amounts = [];
        foreach ($allocations as $row) {
            $invoiceId = (int) ($row['purchase_invoice_id'] ?? 0);
            $amount = Numeric::string($row['amount'] ?? null);

            if ($invoiceId <= 0) {
                throw new PurchaseRuleException('Faktur pada alokasi tidak valid.');
            }
            if (isset($amounts[$invoiceId])) {
                throw new PurchaseRuleException('Faktur yang sama tidak boleh dialokasikan dua kali dalam satu pembayaran.');
            }
            // Lebih dari 4 desimal akan terpotong diam-diam oleh bcmath, jadi ditolak.
            if (! preg_match('/^\d+(\.\d{1,4})?$/', $amount) || bccomp($amount, '0', 4) <= 0) {
                throw new PurchaseRuleException('Nominal alokasi harus berupa angka lebih besar dari 0 (maksimal 4 desimal).');
            }

            $amounts[$invoiceId] = bcadd($amount, '0', 4);
        }

        ksort($amounts);

        return $amounts;
    }

    private function requireMerchant(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        return (int) MerchantContext::id();
    }
}