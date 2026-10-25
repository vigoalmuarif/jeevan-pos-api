<?php

namespace Modules\Purchase\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
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
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Support\DueDate;
use Modules\Purchase\Support\Numeric;
use Modules\Purchase\Support\PurchaseInvoiceTotals;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * Faktur pembelian = tagihan supplier atas satu atau beberapa goods receipt (dasar hutang).
 * Tidak menggerakkan stok.
 *
 * Format input (sudah tervalidasi Form Request):
 *   $data : supplier_id, supplier_invoice_number, invoice_date, due_date?, rounding_amount?,
 *           notes?, branch_id?
 *   $goodsReceiptIds : list<int> penerimaan yang ditagih
 *
 * Aturan inti:
 *  - Hanya GR berstatus posted, belum difakturkan, dan milik supplier yang sama.
 *  - GR langsung DIKAITKAN saat faktur dibuat (draft), bukan saat diposting: sejak itu GR
 *    tidak bisa masuk faktur lain dan tidak bisa di-void. Kaitan dilepas saat draft dihapus
 *    atau faktur di-void.
 *  - Nilai faktur adalah SNAPSHOT dari GR; faktur tidak menghitung ulang diskon/pajak.
 *  - Jatuh tempo kosong diisi otomatis dari tempo pembayaran supplier.
 *  - Semua perubahan berjalan dalam DB::transaction() dengan kunci baris (lockForUpdate).
 *  - Method ubah data menerima id ATAU model (mengikuti service Inventory yang berbasis id).
 */
class PurchaseInvoiceService
{
    /** Kolom header yang boleh berasal dari input user. */
    private const HEADER_FIELDS = ['branch_id', 'supplier_id', 'supplier_invoice_number', 'invoice_date', 'due_date', 'notes'];

    /** Kolom tempo pembayaran (hari) pada tabel suppliers. SESUAIKAN bila namanya berbeda. */
    private const SUPPLIER_TERM_COLUMN = 'payment_term_days';

    public function __construct(
        private readonly PurchaseDocumentNumberService $numbers,
    ) {}

    // -------------------------------------------------------
    // Baca
    // -------------------------------------------------------

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $merchantId = $this->requireMerchant();

        // Overdue DIHITUNG, tidak disimpan: faktur posted, belum lunas, dan lewat jatuh tempo.
        $overdue = AllowedFilter::callback('overdue', function (Builder $query, mixed $value) {
            if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                return;
            }

            $query->where('purchase_invoices.status', DocumentStatus::Posted->value)
                ->where('purchase_invoices.payment_status', '!=', PaymentStatus::Paid->value)
                ->whereDate('purchase_invoices.due_date', '<', now()->toDateString());
        });

        $config = new DataTableConfig(
            allowedFields: [
                'id',
                'document_number',
                'supplier_invoice_number',
                'supplier_id',
                'supplier_name',
                'invoice_date',
                'due_date',
                'status',
                'payment_status',
                'grand_total',
                'paid_amount',
                'balance_amount',
                'is_overdue',
                'created_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('supplier_id', 'purchase_invoices.supplier_id'),
                AllowedFilter::exact('status', 'purchase_invoices.status'),
                AllowedFilter::exact('payment_status', 'purchase_invoices.payment_status'),
                $overdue,
                AllowedFilter::callback('invoice_date_from', fn(Builder $q, mixed $v) => $q->whereDate('purchase_invoices.invoice_date', '>=', $v)),
                AllowedFilter::callback('invoice_date_to', fn(Builder $q, mixed $v) => $q->whereDate('purchase_invoices.invoice_date', '<=', $v)),
            ],
            allowedSorts: [
                'purchase_invoices.document_number',
                'purchase_invoices.invoice_date',
                'purchase_invoices.due_date',
                'purchase_invoices.grand_total',
                'purchase_invoices.balance_amount',
                'suppliers.name',
            ],
            searchableColumns: ['purchase_invoices.document_number', 'purchase_invoices.supplier_invoice_number', 'suppliers.name'],
            defaultSort: '-purchase_invoices.invoice_date',
        );

        // Soft delete faktur diterapkan otomatis oleh model; supplier yang sudah dihapus tetap
        // ditampilkan karena fakturnya adalah catatan hutang yang harus tetap terlihat.
        $query = PurchaseInvoice::query()
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->where('purchase_invoices.merchant_id', $merchantId)
            ->where('suppliers.merchant_id', $merchantId)
            ->select([
                'purchase_invoices.id',
                'purchase_invoices.document_number',
                'purchase_invoices.supplier_invoice_number',
                'purchase_invoices.supplier_id',
                'suppliers.name as supplier_name',
                'purchase_invoices.invoice_date',
                'purchase_invoices.due_date',
                'purchase_invoices.status',
                'purchase_invoices.payment_status',
                'purchase_invoices.grand_total',
                'purchase_invoices.paid_amount',
                'purchase_invoices.balance_amount',
                'purchase_invoices.created_at',
            ])
            ->selectRaw("(purchase_invoices.status = 'posted' and purchase_invoices.payment_status <> 'paid' and purchase_invoices.due_date < current_date) as is_overdue");

        return ServerSideTable::paginate($query, $config, $request);
    }

    /**
     * Detail faktur: header, baris (snapshot, memuat nomor penerimaannya), dan riwayat pembayaran.
     * Baris dibaca dari purchase_invoice_items (bukan dari goods_receipts) agar faktur yang
     * sudah di-void tetap menampilkan isinya walaupun kaitan GR sudah dilepas.
     */
    public function findForCurrentMerchant(int $id): array
    {
        $merchantId = $this->requireMerchant();
        $invoice = PurchaseInvoice::query()->where('merchant_id', $merchantId)->findOrFail($id);

        $supplier = DB::table('suppliers')
            ->where('merchant_id', $merchantId)
            ->where('id', $invoice->supplier_id)
            ->first(['id', 'name']);

        $items = DB::table('purchase_invoice_items as item')
            ->join('goods_receipts as gr', 'gr.id', '=', 'item.goods_receipt_id')
            ->join('product_variants as pv', 'pv.id', '=', 'item.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('units as u', 'u.id', '=', 'item.unit_id')
            ->where('item.merchant_id', $merchantId)
            ->where('item.purchase_invoice_id', $invoice->id)
            ->select([
                'item.id',
                'item.goods_receipt_id',
                'gr.document_number as goods_receipt_number',
                'gr.receipt_date',
                'p.name as product_name',
                'pv.name as variant_name',
                'pv.sku',
                'u.name as unit_name',
                'item.unit_quantity',
                'item.unit_price',
                'item.discount_amount',
                'item.allocated_discount_amount',
                'item.tax_rate',
                'item.tax_amount',
                'item.subtotal',
                'item.line_total',
            ])
            ->orderBy('gr.receipt_date')->orderBy('item.goods_receipt_id')->orderBy('item.id')
            ->get();

        $payments = DB::table('purchase_payment_allocations as alloc')
            ->join('purchase_payments as pay', 'pay.id', '=', 'alloc.purchase_payment_id')
            ->where('alloc.merchant_id', $merchantId)
            ->where('alloc.purchase_invoice_id', $invoice->id)
            ->select([
                'pay.id',
                'pay.document_number',
                'pay.payment_date',
                'pay.payment_method',
                'pay.reference_number',
                'pay.status',
                'alloc.amount',
            ])
            ->orderBy('pay.payment_date')->orderBy('pay.id')
            ->get();

        return [
            'id' => $invoice->id,
            'document_number' => $invoice->document_number,
            'supplier_id' => $invoice->supplier_id,
            'supplier_name' => $supplier?->name,
            'branch_id' => $invoice->branch_id,
            'supplier_invoice_number' => $invoice->supplier_invoice_number,
            'invoice_date' => $invoice->invoice_date,
            'due_date' => $invoice->due_date,
            'status' => $invoice->status,
            'payment_status' => $invoice->payment_status,
            'is_overdue' => $invoice->status === DocumentStatus::Posted
                && $invoice->payment_status !== PaymentStatus::Paid
                && $invoice->due_date !== null
                && $invoice->due_date->lt(today()),
            'items_subtotal' => $invoice->items_subtotal,
            'discount_amount' => $invoice->discount_amount,
            'tax_amount' => $invoice->tax_amount,
            'rounding_amount' => $invoice->rounding_amount,
            'grand_total' => $invoice->grand_total,
            'paid_amount' => $invoice->paid_amount,
            'balance_amount' => $invoice->balance_amount,
            'notes' => $invoice->notes,
            'posted_at' => $invoice->posted_at,
            'voided_at' => $invoice->voided_at,
            'void_reason' => $invoice->void_reason,
            'created_at' => $invoice->created_at,
            'updated_at' => $invoice->updated_at,
            'receipts' => $items->unique('goods_receipt_id')->map(fn($i) => [
                'id' => $i->goods_receipt_id,
                'document_number' => $i->goods_receipt_number,
                'receipt_date' => $i->receipt_date,
            ])->values(),
            'items' => $items,
            'payments' => $payments,
        ];
    }

    /** Penerimaan yang siap difakturkan untuk satu supplier (bahan pilihan pada form faktur). */
    public function invoiceableReceipts(int $supplierId): Collection
    {
        $this->requireMerchant();

        return GoodsReceipt::query()
            ->uninvoiced()
            ->where('supplier_id', $supplierId)
            ->with('warehouse:id,name')
            ->orderBy('receipt_date')
            ->orderBy('id')
            ->get([
                'id',
                'document_number',
                'receipt_date',
                'delivery_note_number',
                'warehouse_id',
                'items_subtotal',
                'discount_amount',
                'tax_amount',
                'grand_total'
            ])
            ->map(fn(GoodsReceipt $r) => [
                'id' => $r->id,
                'document_number' => $r->document_number,
                'receipt_date' => $r->receipt_date,
                'delivery_note_number' => $r->delivery_note_number,
                'warehouse_name' => $r->warehouse?->name,
                'items_subtotal' => $r->items_subtotal,
                'discount_amount' => $r->discount_amount,
                'tax_amount' => $r->tax_amount,
                'grand_total' => $r->grand_total,
            ]);
    }

    // -------------------------------------------------------
    // Tulis
    // -------------------------------------------------------

    /**
     * @param list<int> $goodsReceiptIds
     */
    public function create(array $data, array $goodsReceiptIds): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $goodsReceiptIds) {
            $ids = array_values(array_unique(array_map('intval', $goodsReceiptIds)));
            if ($ids === []) {
                throw new PurchaseRuleException('Pilih minimal satu penerimaan barang untuk difakturkan.');
            }

            // GR dikunci berurutan id (mencegah deadlock) dan HANYA yang memenuhi syarat.
            // Dua orang yang memfakturkan GR yang sama bersamaan: yang kedua menunggu, lalu
            // tidak lagi menemukan GR itu karena sudah terkait ke faktur pertama.
            $receipts = GoodsReceipt::query()
                ->uninvoiced()
                ->where('supplier_id', (int) $data['supplier_id'])
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('items')
                ->get();

            if ($receipts->count() !== count($ids)) {
                throw new PurchaseRuleException(
                    'Sebagian penerimaan tidak dapat difakturkan: belum diposting, sudah difakturkan, atau milik supplier lain.'
                );
            }

            $totals = PurchaseInvoiceTotals::fromReceipts($receipts, Numeric::string($data['rounding_amount'] ?? null));

            // Jatuh tempo kosong -> tanggal faktur + tempo pembayaran supplier (tetap boleh diubah user).
            if (empty($data['due_date'])) {
                $data['due_date'] = $this->defaultDueDate($data);
            }

            $invoice = new PurchaseInvoice();
            $invoice->fill(array_merge(
                Arr::only($data, self::HEADER_FIELDS),
                $totals,
                ['document_number' => $this->numbers->generate('purchase_invoice')],
            ));
            $this->saveOrExplain($invoice);

            // Snapshot baris faktur dari item penerimaan.
            $rows = [];
            foreach ($receipts as $receipt) {
                foreach ($receipt->items as $item) {
                    $rows[] = [
                        'goods_receipt_id' => $receipt->id,
                        'goods_receipt_item_id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        'unit_id' => $item->unit_id,
                        'unit_quantity' => (string) $item->unit_quantity,
                        'unit_price' => (string) $item->unit_price,
                        'discount_amount' => (string) $item->discount_amount,
                        'allocated_discount_amount' => (string) $item->allocated_discount_amount,
                        'tax_rate' => (string) $item->tax_rate,
                        'tax_amount' => (string) $item->tax_amount,
                        'subtotal' => (string) $item->subtotal,
                        'line_total' => (string) $item->line_total,
                    ];
                }
            }
            $invoice->items()->createMany($rows);

            // Kaitkan GR (query builder: kolom ini sengaja tidak fillable di model).
            GoodsReceipt::query()->whereIn('id', $ids)->update(['purchase_invoice_id' => $invoice->id]);

            return $invoice->load('items');
        });
    }

    /**
     * Ubah header draft (nomor faktur supplier, tanggal, jatuh tempo, pembulatan, catatan).
     * Himpunan penerimaan tidak dapat diubah; hapus draft lalu buat ulang bila perlu.
     */
    public function update(int|PurchaseInvoice $invoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice = $this->lock($invoice);

            if (! $invoice->status->isEditable()) {
                throw new PurchaseRuleException('Hanya faktur berstatus draft yang dapat diubah.');
            }

            // Total dihitung ulang dari GR yang terkait (nilai GR tidak berubah sejak diposting).
            $totals = PurchaseInvoiceTotals::fromReceipts(
                $invoice->goodsReceipts()->get(),
                Numeric::string($data['rounding_amount'] ?? $invoice->rounding_amount)
            );

            // supplier_id tidak boleh berubah: GR terkait milik supplier faktur ini.
            $invoice->fill(array_merge(Arr::except(Arr::only($data, self::HEADER_FIELDS), ['supplier_id']), $totals));
            $this->saveOrExplain($invoice);

            return $invoice->load('items');
        });
    }

    public function delete(int|PurchaseInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = $this->lock($invoice);

            if (! $invoice->status->isEditable()) {
                throw new PurchaseRuleException('Hanya draft yang dapat dihapus. Faktur yang sudah diposting dibatalkan (void).');
            }

            $this->releaseReceipts($invoice); // GR boleh difakturkan ulang
            $invoice->delete();               // soft delete
        });
    }

    public function post(int|PurchaseInvoice $invoice): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = $this->lock($invoice);

            if (! $invoice->status->canPost()) {
                throw new PurchaseRuleException('Hanya faktur berstatus draft yang dapat diposting.');
            }

            // GR terkait tidak mungkin di-void selama terkait faktur, tetapi dipastikan lagi:
            // hutang tidak boleh lahir dari penerimaan yang tidak sah.
            $receipts = $invoice->goodsReceipts()->get();
            if ($receipts->isEmpty() || $receipts->contains(fn(GoodsReceipt $r) => $r->status !== DocumentStatus::Posted)) {
                throw new PurchaseRuleException('Faktur hanya boleh menagih penerimaan yang sudah diposting.');
            }

            $invoice->forceFill([
                'status' => DocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => auth('merchant')->id(),
            ])->save();

            return $invoice;
        });
    }

    /**
     * Void faktur. Ditolak bila sudah ada pembayaran aktif (paid_amount > 0): pembayaran harus
     * dibatalkan lebih dulu, supaya hutang dan kas tidak saling bertentangan.
     */
    public function void(int|PurchaseInvoice $invoice, string $reason): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $invoice = $this->lock($invoice);

            if (! $invoice->status->canVoid()) {
                throw new PurchaseRuleException('Hanya faktur yang sudah diposting yang dapat di-void.');
            }
            if (bccomp((string) $invoice->paid_amount, '0', 4) > 0) {
                throw new PurchaseRuleException('Faktur sudah memiliki pembayaran. Batalkan pembayarannya terlebih dahulu.');
            }

            $this->releaseReceipts($invoice);

            $invoice->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => auth('merchant')->id(),
                'void_reason' => $reason,
            ])->save();

            return $invoice;
        });
    }

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    /** Lepas kaitan GR -> faktur agar penerimaan dapat difakturkan lagi (atau di-void). */
    private function releaseReceipts(PurchaseInvoice $invoice): void
    {
        $invoice->goodsReceipts()->update(['purchase_invoice_id' => null]);
    }

    /** Tanggal faktur + tempo pembayaran supplier; null bila supplier tidak punya tempo. */
    private function defaultDueDate(array $data): ?string
    {
        $days = DB::table('suppliers')
            ->where('merchant_id', $this->requireMerchant())
            ->where('id', (int) $data['supplier_id'])
            ->value(self::SUPPLIER_TERM_COLUMN);

        return DueDate::fromTerm((string) $data['invoice_date'], $days);
    }

    /**
     * Nomor faktur supplier yang sama dicegah partial unique index. Pelanggarannya diubah
     * menjadi pesan yang dapat dipahami user; pelanggaran lain dilempar apa adanya.
     */
    private function saveOrExplain(PurchaseInvoice $invoice): void
    {
        try {
            $invoice->save();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'purchase_invoices_supplier_number_unique')) {
                throw new PurchaseRuleException('Nomor faktur supplier ini sudah tercatat untuk supplier tersebut.');
            }

            throw $e;
        }
    }

    /** Kunci baris dan baca ulang (status model dari pemanggil bisa sudah basi). */
    private function lock(int|PurchaseInvoice $invoice): PurchaseInvoice
    {
        $id = $invoice instanceof PurchaseInvoice ? $invoice->getKey() : $invoice;

        return PurchaseInvoice::query()->lockForUpdate()->findOrFail($id);
    }

    private function requireMerchant(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        return (int) MerchantContext::id();
    }
}
