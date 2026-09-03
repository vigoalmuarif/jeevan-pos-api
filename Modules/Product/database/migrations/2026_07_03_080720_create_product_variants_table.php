<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * is_default = true: varian otomatis untuk produk yang secara bisnis
     * tidak punya varian (mis. Beras Curah). Ini memastikan StockService,
     * PriceResolverService, dan SalesService selalu beroperasi di level
     * varian tanpa percabangan logic "punya varian / tidak".
     *
     * merchant_id didenormalisasi dari products untuk keperluan MerchantScope
     * & unique constraint SKU per merchant tanpa join ke products.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('sku');
            $table->string('name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'sku']);
            $table->index(['product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};