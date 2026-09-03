<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Barcode & cost/price melekat di sini (bukan di product_variants),
     * karena satu varian yang sama bisa dijual dalam beberapa satuan
     * dengan barcode & harga berbeda (mis. Pcs vs Dus).
     *
     * default_price = harga retail pusat (cost x (1 + markup%)) yang jadi
     * fallback terakhir PriceResolverService jika tidak ada rule di
     * product_prices yang cocok.
     */
    public function up(): void
    {
        Schema::create('product_variant_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->boolean('is_base')->default(false);
            $table->string('barcode')->nullable();

            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('default_price', 15, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_variant_id', 'unit_id']);
            $table->unique(['merchant_id', 'barcode']);
            $table->index(['product_variant_id', 'is_base']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_units');
    }
};