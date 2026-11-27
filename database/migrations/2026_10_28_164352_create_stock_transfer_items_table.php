<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer stok antar gudang, DUA LANGKAH: kirim lalu terima.
 *
 * Status: draft -> shipped -> received ; cancelled (dari draft, atau dari shipped sebelum diterima).
 *  - Kirim   : stok keluar dari gudang asal, HPP dibekukan sebesar avg_cost asal saat itu.
 *  - Terima  : stok masuk ke gudang tujuan dengan biaya yang sama, sehingga HPP terbawa dan tidak
 *              berubah akibat perpindahan.
 *  - Barang dalam perjalanan direpresentasikan oleh DOKUMEN ini (dikirim - diterima), bukan gudang
 *    virtual (tipe gudang virtual sengaja ditunda).
 *  - Selisih terima (diterima < dikirim) dicatat sebagai selisih pada item beserta receive_notes;
 *    selisih itu tidak kembali ke gudang asal (dianggap hilang/rusak di perjalanan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('unit_quantity', 20, 4);
            $table->decimal('conversion_rate', 20, 6);
            $table->decimal('base_quantity', 20, 4);
            $table->decimal('received_base_quantity', 20, 4)->nullable();
            $table->decimal('variance_base_quantity', 20, 4)->default(0);
            $table->decimal('unit_cost', 20, 4)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'stock_transfer_id'], 'stock_transfer_items_merchant_transfer_index');
            $table->index(['stock_transfer_id', 'product_variant_id'], 'stock_transfer_items_transfer_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
