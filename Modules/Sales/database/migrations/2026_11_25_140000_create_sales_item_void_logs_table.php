<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_item_void_logs')) {
            Schema::create('sales_item_void_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('sales_transaction_id')->constrained('sales_transactions')->cascadeOnDelete();
                $table->foreignId('dining_table_id')->nullable()->constrained('dining_tables')->nullOnDelete();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('product_variant_unit_id')->nullable();
                $table->string('item_name', 180);
                $table->decimal('voided_qty', 15, 4);
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('voided_amount', 15, 2)->default(0);
                $table->string('reason_code', 40);
                $table->string('reason_note', 255)->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['merchant_id', 'branch_id', 'created_at'], 'sales_item_void_logs_branch_idx');
                $table->index(['merchant_id', 'sales_transaction_id'], 'sales_item_void_logs_tx_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_item_void_logs');
    }
};
