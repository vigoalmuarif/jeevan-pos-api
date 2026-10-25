<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item Goods Receipt.
 *
 * Perhitungan nilai (disimpan sebagai snapshot, tidak dihitung ulang saat dibaca):
 *   gross                     = unit_quantity * unit_price
 *   subtotal                  = gross - discount_amount - allocated_discount_amount   (eksklusif pajak)
 *   line_total                = subtotal + tax_amount
 *   cost_amount (dasar HPP)   = subtotal  (+ tax_amount bila header.tax_in_cost = true)
 *   unit_cost (per sat. dasar)= cost_amount / base_quantity
 *
 * unit_cost inilah yang dikirim ke StockService::recordMutation() sehingga harga beli
 * (setelah diskon item dan alokasi diskon header) memengaruhi moving average.
 *
 * purchase_order_item_id : NULL bila pembelian langsung tanpa PO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('unit_quantity', 18, 4);
            $table->decimal('conversion_rate', 18, 6)->default(1);
            $table->decimal('base_quantity', 18, 4);

            $table->decimal('unit_price', 18, 4);                      // per satuan input
            $table->string('discount_type', 10)->nullable();           // percent | amount
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('allocated_discount_amount', 18, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);

            $table->decimal('cost_amount', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);

            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index('goods_receipt_id', 'goods_receipt_items_receipt_index');
            $table->index('purchase_order_item_id', 'goods_receipt_items_order_item_index');
            $table->index(['merchant_id', 'product_variant_id'], 'goods_receipt_items_variant_index');
        });

        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_quantity_check CHECK (unit_quantity > 0 AND base_quantity > 0)');
        DB::statement("ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_discount_type_check CHECK (discount_type IS NULL OR discount_type IN ('percent','amount'))");
        DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_amounts_check CHECK (unit_price >= 0 AND cost_amount >= 0 AND unit_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};