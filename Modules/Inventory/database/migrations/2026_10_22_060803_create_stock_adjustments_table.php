<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumen penyesuaian stok (header).
 *
 * Alur status: draft -> posted -> voided
 *  - draft  : boleh diubah / dihapus (soft delete), belum memengaruhi stok
 *  - posted : mutasi tercatat di stock_mutations, dokumen tidak boleh diubah
 *  - voided : dibatalkan lewat mutasi pembalik, dokumen tetap tersimpan untuk audit
 *
 * Satu dokumen boleh berisi baris penambahan DAN pengurangan
 * (arah ditentukan tanda unit_quantity pada item).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();

            $table->string('document_number', 50);                    // dari DocumentNumberService
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->date('adjustment_date');
            $table->string('reason', 30);                             // damaged | expired | lost | found | correction | other
            $table->string('status', 20)->default('draft');           // draft | posted | voided
            $table->text('notes')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'document_number'], 'stock_adjustments_merchant_number_unique');
            $table->index(['merchant_id', 'status', 'adjustment_date'], 'stock_adjustments_status_date_index');
            $table->index('warehouse_id', 'stock_adjustments_warehouse_index');
        });

        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_status_check CHECK (status IN ('draft','posted','voided'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};