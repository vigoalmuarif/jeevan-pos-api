<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran ke supplier (header). Satu pembayaran dapat dialokasikan ke satu atau
 * beberapa faktur (purchase_invoices) lewat purchase_payment_allocations.
 *
 * Tahap 1: seluruh nilai pembayaran WAJIB teralokasi (SUM(allocations.amount) = amount),
 * divalidasi di service. Seluruh faktur yang dialokasikan harus milik supplier yang sama. Uang muka / saldo tak teralokasi belum didukung.
 *
 * status: posted | voided. Pembatalan mengembalikan paid_amount faktur terkait.
 * payment_method berupa string (cash, transfer, giro, other); dapat dipindah ke master
 * metode pembayaran bila nanti ada modul Keuangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();

            $table->string('document_number', 50);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('payment_date');
            $table->string('payment_method', 30);
            $table->decimal('amount', 18, 4);
            $table->string('reference_number', 50)->nullable();       // no. transfer / giro
            $table->text('notes')->nullable();

            $table->string('status', 10)->default('posted');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['merchant_id', 'document_number'], 'purchase_payments_merchant_number_unique');
            $table->index(['merchant_id', 'supplier_id', 'payment_date'], 'purchase_payments_supplier_date_index');
        });

        DB::statement("ALTER TABLE purchase_payments ADD CONSTRAINT purchase_payments_status_check CHECK (status IN ('posted','voided'))");
        DB::statement('ALTER TABLE purchase_payments ADD CONSTRAINT purchase_payments_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
    }
};