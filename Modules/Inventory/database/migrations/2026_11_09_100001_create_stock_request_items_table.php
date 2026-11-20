<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('stock_request_id')->constrained('stock_requests')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('unit_quantity', 20, 4);
            $table->decimal('conversion_rate', 20, 6);
            $table->decimal('base_quantity', 20, 4);
            $table->decimal('approved_base_quantity', 20, 4)->nullable();
            $table->decimal('transferred_base_quantity', 20, 4)->default(0);

            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'stock_request_id'], 'stock_request_items_merchant_request_index');
            $table->index(['stock_request_id', 'product_variant_id'], 'stock_request_items_request_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_request_items');
    }
};

