<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Order (PO) - dokumen perencanaan, OPSIONAL dipakai merchant.
 * PO tidak menggerakkan stok; stok hanya bergerak lewat goods_receipts.
 *
 * Status: draft -> approved -> partially_received -> received
 *         approved/partially_received -> closed (ditutup manual)
 *         draft/approved -> cancelled
 *
 * Alur nilai (dinormalisasi, selalu EKSKLUSIF pajak pada kolom tersimpan):
 *   items_subtotal  = SUM(item.gross - item.discount_amount)
 *   discount_amount = diskon header (dialokasikan ke item -> allocated_discount_amount)
 *   tax_amount      = SUM(item.tax_amount)
 *   grand_total     = items_subtotal - discount_amount + tax_amount
 * Jika is_tax_inclusive = true, service mengekstrak pajak dari harga saat menghitung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();

            $table->string('document_number', 50);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete(); // gudang tujuan
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 20)->default('draft');

            $table->boolean('is_tax_inclusive')->default(false);
            $table->decimal('items_subtotal', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable();            // percent | amount
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'document_number'], 'purchase_orders_merchant_number_unique');
            $table->index(['merchant_id', 'status', 'order_date'], 'purchase_orders_status_date_index');
            $table->index(['merchant_id', 'supplier_id'], 'purchase_orders_supplier_index');
        });

        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft','approved','partially_received','received','closed','cancelled'))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_discount_type_check CHECK (discount_type IS NULL OR discount_type IN ('percent','amount'))");
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_amounts_check CHECK (items_subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND grand_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};