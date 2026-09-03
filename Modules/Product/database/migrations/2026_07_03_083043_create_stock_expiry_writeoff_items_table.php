<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * stock_batch_id ditunjuk eksplisit oleh user (bukan lewat FEFO
     * strategy) karena user memang sengaja memilih batch spesifik yang
     * mau dibuang.
     */
    public function up(): void
    {
        Schema::create('stock_expiry_writeoff_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_expiry_writeoff_id')->constrained('stock_expiry_writeoffs')->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->restrictOnDelete();

            $table->decimal('quantity', 15, 4);

            $table->timestamps();

            $table->index(['stock_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_expiry_writeoff_items');
    }
};