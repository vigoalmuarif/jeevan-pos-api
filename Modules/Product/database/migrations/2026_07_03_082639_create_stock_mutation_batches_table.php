<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu stock_mutation (mis. jual 5 pcs) bisa menyedot dari lebih dari
     * 1 batch sekaligus kalau batch pertama tidak cukup. Tabel ini mencatat
     * berapa diambil dari batch mana - stock_mutations di Core Inventory
     * TIDAK diubah/ditambah kolom apapun untuk ini.
     */
    public function up(): void
    {
        Schema::create('stock_mutation_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_mutation_id');
            $table->foreignId('stock_batch_id')->constrained('stock_batches')->cascadeOnDelete();

            $table->decimal('quantity', 15, 4);

            $table->timestamps();

            $table->index(['stock_mutation_id']);
            $table->index(['stock_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_mutation_batches');
    }
};