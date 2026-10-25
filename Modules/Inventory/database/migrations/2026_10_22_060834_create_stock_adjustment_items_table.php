<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item penyesuaian stok.
 *
 * - unit_quantity  : qty sesuai satuan yang diinput user (bertanda: + tambah, - kurang)
 * - conversion_rate: snapshot konversi satuan input -> satuan dasar saat dokumen dibuat
 * - base_quantity  : bertanda, satuan dasar (= unit_quantity * conversion_rate),
 *                    inilah yang dikirim ke StockService::recordMutation()
 * - unit_cost      : wajib bila penambahan ingin memengaruhi moving average
 *                    (NULL = pakai avg_cost berjalan)
 *
 * merchant_id didenormalisasi agar MerchantScope berlaku langsung pada item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('unit_quantity', 18, 4);
            $table->decimal('conversion_rate', 18, 6)->default(1);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->index('stock_adjustment_id', 'stock_adjustment_items_adjustment_index');
            $table->index(['merchant_id', 'product_variant_id'], 'stock_adjustment_items_variant_index');
        });

        DB::statement('ALTER TABLE stock_adjustment_items ADD CONSTRAINT stock_adjustment_items_quantity_nonzero_check CHECK (base_quantity <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};