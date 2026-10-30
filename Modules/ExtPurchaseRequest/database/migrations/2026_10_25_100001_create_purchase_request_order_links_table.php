<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penghubung item PR -> item PO (banyak ke banyak): satu PO dapat memenuhi beberapa PR
 * (penggabungan kebutuhan antar cabang), dan satu item PR dapat dipecah ke beberapa PO.
 *
 * Disimpan di tabel TERPISAH (bukan kolom purchase_request_id pada PO) agar tabel PO tetap
 * tidak mengenal PR: PO sah berdiri tanpa PR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_order_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('purchase_request_item_id')->constrained('purchase_request_items')->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->decimal('base_quantity', 18, 4);
            $table->timestamps();

            $table->unique(['purchase_request_item_id', 'purchase_order_item_id'], 'purchase_request_order_links_unique');
            $table->index('purchase_order_item_id', 'purchase_request_order_links_order_item_index');
            $table->index('purchase_request_item_id', 'purchase_request_order_links_request_item_index');
        });

        DB::statement('ALTER TABLE purchase_request_order_links ADD CONSTRAINT purchase_request_order_links_quantity_check CHECK (base_quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_order_links');
    }
};
