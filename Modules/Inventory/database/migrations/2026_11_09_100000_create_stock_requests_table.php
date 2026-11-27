<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permintaan mutasi / transfer stok (Stock Request) dari Cabang ke Gudang Penyedia / Gudang Pusat.
 *
 * Status: draft -> submitted -> approved -> partial_transferred -> transferred ; rejected / cancelled.
 *  - draft               : dibuat oleh staf cabang, belum diajukan.
 *  - submitted           : diajukan ke Gudang Pusat / Supervisor, menunggu persetujuan.
 *  - approved            : kuantitas disetujui, siap diterbitkan StockTransfer.
 *  - partial_transferred : sebagian item/kuantitas sudah diterbitkan StockTransfer.
 *  - transferred         : seluruh kuantitas yang disetujui telah selesai dibuatkan StockTransfer.
 *  - rejected            : permohonan ditolak oleh pusat/supervisor.
 *  - cancelled           : dibatalkan oleh peminta sebelum disetujui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();

            $table->string('document_number', 50);
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('source_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->date('request_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 25)->default('draft');
            $table->text('notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reject_reason', 255)->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'document_number'], 'stock_requests_merchant_number_unique');
            $table->index(['merchant_id', 'status', 'request_date'], 'stock_requests_status_date_index');
            $table->index('branch_id', 'stock_requests_branch_index');
            $table->index('destination_warehouse_id', 'stock_requests_destination_warehouse_index');
            $table->index('source_warehouse_id', 'stock_requests_source_warehouse_index');
        });

        DB::statement("ALTER TABLE stock_requests ADD CONSTRAINT stock_requests_status_check CHECK (status IN ('draft','submitted','approved','partial_transferred','transferred','rejected','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_requests');
    }
};

