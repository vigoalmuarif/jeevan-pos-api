<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('sales_transaction_id')->constrained('sales_transactions')->cascadeOnDelete();
            $table->string('payment_method', 30); // cash, qris, bank_transfer, debit_card, credit_card, ewallet
            $table->decimal('amount', 15, 2);
            $table->string('reference_number', 100)->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['merchant_id', 'sales_transaction_id'], 'sales_payments_tx_idx');
            $table->index(['merchant_id', 'payment_method'], 'sales_payments_method_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_payments');
    }
};
