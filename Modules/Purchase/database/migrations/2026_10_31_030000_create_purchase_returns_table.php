<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Return (Retur Pembelian) - DEC-10.
 *
 * Dokumen pengembalian barang ke supplier berdasarkan Goods Receipt yang telah diposting.
 * - goods_receipt_id        -> referensi penerimaan sumber.
 * - purchase_invoice_id     -> faktur yang terkait (bila sudah difakturkan).
 * - status                  : draft -> posted -> voided.
 * - settlement_method       : invoice_deduction | cash_refund | credit_balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();

            $table->string('document_number', 50);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();

            $table->date('return_date');
            $table->string('status', 20)->default('draft');
            $table->string('settlement_method', 30)->default('invoice_deduction');
            $table->string('reason_code', 30)->default('damaged');

            $table->decimal('subtotal', 18, 4)->default(0);
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

            $table->unique(['merchant_id', 'document_number'], 'purchase_returns_merchant_number_unique');
            $table->index(['merchant_id', 'status', 'return_date'], 'purchase_returns_status_date_index');
            $table->index(['merchant_id', 'supplier_id', 'status'], 'purchase_returns_supplier_status_index');
            $table->index('goods_receipt_id', 'purchase_returns_receipt_index');
            $table->index('purchase_invoice_id', 'purchase_returns_invoice_index');
        });

        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_status_check CHECK (status IN ('draft','posted','voided'))");
        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_settlement_check CHECK (settlement_method IN ('invoice_deduction','cash_refund','credit_balance'))");
        DB::statement('ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_amounts_check CHECK (subtotal >= 0 AND tax_amount >= 0 AND grand_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};

