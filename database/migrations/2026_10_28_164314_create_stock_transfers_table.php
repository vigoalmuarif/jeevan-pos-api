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
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();

            $table->string('document_number', 50);
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('transfer_date');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->text('receive_notes')->nullable();               // wajib bila ada selisih saat terima

            $table->timestamp('shipped_at')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'document_number'], 'stock_transfers_merchant_number_unique');
            $table->index(['merchant_id', 'status', 'transfer_date'], 'stock_transfers_status_date_index');
            $table->index('from_warehouse_id', 'stock_transfers_from_warehouse_index');
            $table->index('to_warehouse_id', 'stock_transfers_to_warehouse_index');
        });

        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_check CHECK (status IN ('draft','shipped','received','cancelled'))");
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_warehouses_check CHECK (from_warehouse_id <> to_warehouse_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
