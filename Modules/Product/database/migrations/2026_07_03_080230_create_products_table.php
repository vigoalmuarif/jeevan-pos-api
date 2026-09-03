<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tidak ada kolom cost/price/barcode di sini secara sengaja.
     * Data tersebut melekat pada product_variant_units, karena satu produk
     * bisa punya banyak varian dengan harga & barcode berbeda-beda.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['product', 'service'])->default('product');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();

            $table->boolean('track_stock')->default(true);
            $table->boolean('is_taxable')->default(false);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('min_stock', 15, 4)->nullable();
            $table->decimal('weight', 10, 3)->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'slug']);
            $table->index(['merchant_id', 'is_active']);
            $table->index(['merchant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};