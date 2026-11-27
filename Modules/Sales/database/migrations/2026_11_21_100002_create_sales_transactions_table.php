<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained('cashier_shifts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('transaction_number', 50);
            $table->string('idempotency_key', 100)->nullable();
            $table->string('status', 20)->default('completed'); // held, completed, voided
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('discount_type', 20)->default('none'); // none, fixed, percentage
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);
            $table->string('hold_label', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'transaction_number'], 'sales_transactions_merchant_number_unique');
            $table->unique(['merchant_id', 'idempotency_key'], 'sales_transactions_merchant_idempotency_unique');
            $table->index(['merchant_id', 'branch_id', 'status', 'sold_at'], 'sales_transactions_filter_idx');
            $table->index(['merchant_id', 'cashier_shift_id', 'status'], 'sales_transactions_shift_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_transactions');
    }
};
