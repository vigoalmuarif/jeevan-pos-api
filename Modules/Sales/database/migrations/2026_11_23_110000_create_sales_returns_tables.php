<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('sales_transaction_id')->constrained('sales_transactions')->cascadeOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained('cashier_shifts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('return_number', 50);
            $table->string('status', 20)->default('completed');
            $table->string('refund_method', 30)->default('cash');
            $table->text('reason');

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_refund', 15, 2)->default(0);
            $table->decimal('total_cogs_reversed', 15, 2)->default(0);

            $table->timestamp('returned_at');
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'return_number']);
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'sales_transaction_id']);
            $table->index(['merchant_id', 'cashier_shift_id']);
            $table->index(['merchant_id', 'returned_at']);
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('sales_transaction_item_id')->constrained('sales_transaction_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('product_variant_unit_id')->constrained('product_variant_units')->cascadeOnDelete();

            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('unit_name', 100);
            $table->string('unit_code', 30)->nullable();

            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->decimal('quantity', 15, 4);
            $table->decimal('base_quantity', 15, 4);

            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cogs', 15, 2)->default(0);

            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total_refund', 15, 2)->default(0);

            $table->boolean('restock')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'sales_return_id']);
            $table->index(['merchant_id', 'sales_transaction_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
    }
};
