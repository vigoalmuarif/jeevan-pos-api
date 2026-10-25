<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alokasi pembayaran ke faktur (purchase_invoices) - dasar pelunasan bertahap.
 *
 * Contoh: faktur Rp 10.000.000 dibayar 3x -> 3 pembayaran, masing-masing punya satu
 * baris alokasi ke faktur yang sama. Satu pembayaran juga bisa melunasi banyak faktur.
 *
 * Saat pembayaran diposting/void, service (dalam transaksi + lockForUpdate pada faktur)
 * memperbarui purchase_invoices.paid_amount dan payment_status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('purchase_payment_id')->constrained('purchase_payments')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamps();

            $table->unique(['purchase_payment_id', 'purchase_invoice_id'], 'purchase_payment_allocations_unique');
            $table->index('purchase_invoice_id', 'purchase_payment_allocations_invoice_index');
        });

        DB::statement('ALTER TABLE purchase_payment_allocations ADD CONSTRAINT purchase_payment_allocations_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payment_allocations');
    }
};