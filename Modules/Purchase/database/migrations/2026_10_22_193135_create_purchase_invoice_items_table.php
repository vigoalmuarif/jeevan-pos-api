<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baris faktur pembelian. Setiap baris merujuk satu item goods receipt dan menyimpan
 * SNAPSHOT nilai tagihan (disalin saat faktur dibuat) agar faktur tetap utuh sebagai
 * dokumen akuntansi walaupun data master berubah.
 *
 *   subtotal   = (unit_quantity * unit_price) - discount_amount - allocated_discount_amount   (eksklusif pajak)
 *   line_total = subtotal + tax_amount
 *
 * goods_receipt_id didenormalisasi untuk pengelompokan baris per penerimaan dan
 * penelusuran balik tanpa join tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignId('goods_receipt_item_id')->constrained('goods_receipt_items')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('unit_quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('allocated_discount_amount', 18, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);

            $table->timestamps();

            $table->index('purchase_invoice_id', 'purchase_invoice_items_invoice_index');
            $table->index('goods_receipt_id', 'purchase_invoice_items_receipt_index');
            $table->index('goods_receipt_item_id', 'purchase_invoice_items_receipt_item_index');
        });

        DB::statement('ALTER TABLE purchase_invoice_items ADD CONSTRAINT purchase_invoice_items_amounts_check CHECK (unit_quantity > 0 AND unit_price >= 0 AND subtotal >= 0 AND line_total >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
    }
};