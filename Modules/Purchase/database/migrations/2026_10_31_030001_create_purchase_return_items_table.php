<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item Purchase Return (DEC-10).
 *
 * Mengaitkan kuantitas retur dengan baris penerimaan (goods_receipt_items)
 * dan membawa nilai unit_cost asli untuk pembalikan moving average stok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('goods_receipt_item_id')->constrained('goods_receipt_items')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('unit_quantity', 18, 4);
            $table->decimal('conversion_rate', 18, 6)->default(1);
            $table->decimal('base_quantity', 18, 4);

            $table->decimal('unit_price', 18, 4);                      // per satuan input
            $table->decimal('unit_cost', 18, 4);                       // HPP masuk asli dari GR item
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);

            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index('purchase_return_id', 'purchase_return_items_return_index');
            $table->index('goods_receipt_item_id', 'purchase_return_items_receipt_item_index');
            $table->index(['merchant_id', 'product_variant_id'], 'purchase_return_items_variant_index');
        });

        DB::statement('ALTER TABLE purchase_return_items ADD CONSTRAINT purchase_return_items_quantity_check CHECK (unit_quantity > 0 AND base_quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};

