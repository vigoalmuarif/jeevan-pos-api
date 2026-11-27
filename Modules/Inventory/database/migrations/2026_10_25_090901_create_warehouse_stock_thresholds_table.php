<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batas stok per (gudang, varian): sumber REKOMENDASI pembelian otomatis (low stock).
 *
 * Tabel TERPISAH dari warehouse_stocks karena baris warehouse_stocks baru dibuat saat mutasi
 * pertama, sedangkan batas harus dapat diatur SEBELUM ada stok. Dimiliki module Inventory
 * (kebijakan stok); Purchase hanya membacanya.
 *
 *  - min_quantity : titik pesan ulang. Posisi stok <= min berarti perlu dibeli.
 *  - max_quantity : target level stok. Qty rekomendasi = max - posisi stok.
 * Keduanya dalam SATUAN DASAR (sama dengan warehouse_stocks.quantity).
 *
 * "Posisi stok" = stok saat ini + sudah dipesan (PO terbuka) + sudah diminta (PR terbuka),
 * dihitung ReorderRecommendationService agar rekomendasi tidak berulang untuk barang yang
 * sebenarnya sudah dipesan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();

            $table->decimal('min_quantity', 18, 4);
            $table->decimal('max_quantity', 18, 4);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'product_variant_id'], 'warehouse_stock_thresholds_warehouse_variant_unique');
            $table->index(['merchant_id', 'is_active'], 'warehouse_stock_thresholds_merchant_active_index');
            $table->index('product_variant_id', 'warehouse_stock_thresholds_variant_index');
        });

        DB::statement('ALTER TABLE warehouse_stock_thresholds ADD CONSTRAINT warehouse_stock_thresholds_range_check CHECK (min_quantity >= 0 AND max_quantity >= min_quantity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_thresholds');
    }
};