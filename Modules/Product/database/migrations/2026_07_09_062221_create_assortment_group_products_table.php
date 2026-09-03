<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assortment_group_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assortment_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['assortment_group_id', 'product_variant_id'], 'assortment_group_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assortment_group_products');
    }
};
