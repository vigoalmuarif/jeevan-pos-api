<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faktur Pembelian (Purchase Invoice) = dokumen TAGIHAN dari supplier, dasar hutang
 * dan pelunasan bertahap. Tidak menggerakkan stok (stok hanya lewat goods_receipts).
 *
 * - Satu faktur dapat menagih BANYAK goods receipt milik supplier yang sama
 *   (goods_receipts.purchase_invoice_id). Satu goods receipt hanya boleh masuk
 *   ke satu faktur aktif.
 * - Total faktur disalin (snapshot) dari goods receipt terkait, ditambah rounding_amount
 *   (pembulatan / selisih kecil dengan tagihan supplier, bertanda +/-).
 *     grand_total = items_subtotal - discount_amount + tax_amount + rounding_amount
 * - Tahap 1 belum mendukung selisih HARGA antara faktur dan penerimaan (price variance);
 *   HPP tetap berasal dari goods receipt.
 *
 * status         : draft -> posted -> voided   (hanya faktur posted yang dapat dibayar)
 * payment_status : unpaid | partial | paid     (dijaga service lewat purchase_payment_allocations;
 *                  paid_amount dimutakhirkan dalam transaksi dengan lockForUpdate pada baris faktur)
 * balance_amount : kolom generated (grand_total - paid_amount) untuk filter hutang berjalan.
 *
 * Nomor faktur supplier unik per supplier selama faktur tidak void / tidak dihapus
 * (partial unique index), mencegah input ganda tagihan yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();

            $table->string('document_number', 50);                     // nomor internal (DocumentNumberService)
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('supplier_invoice_number', 50);             // nomor faktur dari supplier
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            $table->string('status', 20)->default('draft');

            $table->decimal('items_subtotal', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('rounding_amount', 18, 4)->default(0);     // bertanda
            $table->decimal('grand_total', 18, 4)->default(0);

            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->decimal('balance_amount', 18, 4)->storedAs('grand_total - paid_amount');
            $table->string('payment_status', 10)->default('unpaid');

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

            $table->unique(['merchant_id', 'document_number'], 'purchase_invoices_merchant_number_unique');
            $table->index(['merchant_id', 'status', 'invoice_date'], 'purchase_invoices_status_date_index');
            $table->index(['merchant_id', 'supplier_id', 'payment_status'], 'purchase_invoices_supplier_payment_index');
            $table->index(['merchant_id', 'payment_status', 'due_date'], 'purchase_invoices_payable_due_index');
        });

        DB::statement("CREATE UNIQUE INDEX purchase_invoices_supplier_number_unique ON purchase_invoices (merchant_id, supplier_id, supplier_invoice_number) WHERE status <> 'voided' AND deleted_at IS NULL");
        DB::statement("ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_status_check CHECK (status IN ('draft','posted','voided'))");
        DB::statement("ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_payment_status_check CHECK (payment_status IN ('unpaid','partial','paid'))");
        DB::statement('ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_amounts_check CHECK (items_subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND grand_total >= 0)');
        DB::statement('ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_paid_amount_check CHECK (paid_amount >= 0 AND paid_amount <= grand_total)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};