<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods Receipt (Penerimaan Barang) = satu-satunya dokumen pembelian yang menggerakkan stok
 * dan menjadi dasar valuasi HPP. Penagihan dan hutang ada di purchase_invoices.
 *
 * - purchase_order_id NULL   -> pembelian langsung tanpa PO.
 * - Satu PO dapat memiliki banyak GR (penerimaan parsial).
 * - purchase_invoice_id      -> faktur yang menagih GR ini (NULL = belum difakturkan).
 *                               Dikosongkan service saat faktur di-void. GR yang sudah
 *                               difakturkan tidak boleh di-void sebelum faktur di-void.
 * - delivery_note_number     -> nomor surat jalan dari supplier.
 * - status                   : draft -> posted -> voided
 * - tax_in_cost              : snapshot kebijakan merchant. false = PPN masukan dapat
 *                              dikreditkan (tidak masuk HPP); true = pajak ikut menjadi HPP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();

            $table->string('document_number', 50);
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('receipt_date');
            $table->string('delivery_note_number', 50)->nullable();

            $table->string('status', 20)->default('draft');

            $table->boolean('is_tax_inclusive')->default(false);
            $table->boolean('tax_in_cost')->default(false);
            $table->decimal('items_subtotal', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable();            // percent | amount
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'document_number'], 'goods_receipts_merchant_number_unique');
            $table->index(['merchant_id', 'status', 'receipt_date'], 'goods_receipts_status_date_index');
            $table->index(['merchant_id', 'supplier_id', 'status'], 'goods_receipts_supplier_status_index');
            $table->index('purchase_order_id', 'goods_receipts_order_index');
            $table->index('purchase_invoice_id', 'goods_receipts_invoice_index');
        });

        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_status_check CHECK (status IN ('draft','posted','voided'))");
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_discount_type_check CHECK (discount_type IS NULL OR discount_type IN ('percent','amount'))");
        DB::statement('ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_amounts_check CHECK (items_subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND grand_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};