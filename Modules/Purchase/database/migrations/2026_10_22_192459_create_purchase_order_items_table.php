<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item PO.
 *
 * - received_base_quantity : akumulasi qty (satuan dasar) yang sudah diterima dari semua
 *   goods receipt berstatus posted. Diperbarui service saat post/void GR, dipakai untuk
 *   menentukan sisa qty dan status PO (partially_received / received) tanpa agregasi berat.
 * - subtotal   : dasar pengenaan pajak = gross - discount_amount - allocated_discount_amount (eksklusif pajak)
 * - line_total : subtotal + tax_amount
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('unit_quantity', 18, 4);
            $table->decimal('conversion_rate', 18, 6)->default(1);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('received_base_quantity', 18, 4)->default(0);

            $table->decimal('unit_price', 18, 4);                      // per satuan input
            $table->string('discount_type', 10)->nullable();           // percent | amount
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('allocated_discount_amount', 18, 4)->default(0); // porsi diskon header
            $table->decimal('tax_rate', 5, 2)->default(0);             // snapshot persen pajak
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);

            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index('purchase_order_id', 'purchase_order_items_order_index');
            $table->index(['merchant_id', 'product_variant_id'], 'purchase_order_items_variant_index');
        });

        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_quantity_check CHECK (unit_quantity > 0 AND base_quantity > 0 AND received_base_quantity >= 0)');
        DB::statement("ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_discount_type_check CHECK (discount_type IS NULL OR discount_type IN ('percent','amount'))");
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_price_check CHECK (unit_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};