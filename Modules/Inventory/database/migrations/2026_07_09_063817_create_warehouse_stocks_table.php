<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo stok saat ini per (warehouse, product_variant) dalam SATUAN DASAR.
 *
 * Tabel ini hanya boleh diubah lewat StockService::recordMutation()
 * (satu-satunya pintu mutasi stok). Sumber kebenaran histori = stock_mutations.
 *
 * Tidak ada CHECK quantity >= 0 karena stok negatif dikontrol per merchant
 * lewat setting (inventory.allow_negative_stock) di level service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();

            $table->decimal('quantity', 18, 4)->default(0);   // satuan dasar
            $table->decimal('avg_cost', 18, 4)->default(0);   // moving average cost per satuan dasar
            $table->timestamp('last_mutated_at')->nullable();

            $table->timestamps();

            $table->unique(['warehouse_id', 'product_variant_id'], 'warehouse_stocks_warehouse_variant_unique');
            $table->index(['merchant_id', 'product_variant_id'], 'warehouse_stocks_merchant_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};