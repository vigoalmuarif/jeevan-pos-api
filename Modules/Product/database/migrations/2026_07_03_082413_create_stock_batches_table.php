<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qty_remaining didenormalisasi (running balance) supaya query FEFO
     * (urut expiry_date ASC, ambil yang qty_remaining > 0) cepat tanpa
     * hitung ulang dari ledger stock_mutation_batches tiap kali.
     *
     * unique(product_variant_id, warehouse_id, expiry_date) menegakkan
     * kebijakan merge batch: penerimaan baru dengan expiry yang sama
     * akan increment qty_received/qty_remaining batch existing (upsert),
     * bukan membuat baris baru. batch_number tetap nullable untuk
     * kebutuhan traceability supplier lot number di masa depan.
     */
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            $table->string('batch_number')->nullable();
            $table->date('expiry_date');

            $table->decimal('qty_received', 15, 4)->default(0);
            $table->decimal('qty_remaining', 15, 4)->default(0);

            $table->nullableMorphs('source');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['product_variant_id', 'warehouse_id', 'expiry_date'], 'stock_batches_merge_unique');
            $table->index(['product_variant_id', 'warehouse_id', 'expiry_date', 'qty_remaining'], 'stock_batches_fefo_index');
            $table->index(['expiry_date', 'qty_remaining']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};