<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->string('order_type', 20)->default('retail')->after('status');
            $table->string('queue_number', 20)->nullable()->after('order_type');
            $table->decimal('service_charge_rate', 5, 2)->default(0)->after('discount_amount');
            $table->decimal('service_charge_amount', 15, 2)->default(0)->after('service_charge_rate');

            $table->index(['merchant_id', 'branch_id', 'order_type'], 'sales_transactions_order_type_idx');
        });

        Schema::table('sales_transaction_items', function (Blueprint $table) {
            $table->decimal('modifiers_total', 15, 2)->default(0)->after('unit_price');
        });

        Schema::create('sales_transaction_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('sales_transaction_item_id')
                ->constrained('sales_transaction_items')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('modifier_group_id')->nullable();
            $table->unsignedBigInteger('modifier_option_id')->nullable();
            $table->string('modifier_group_name', 120);
            $table->string('modifier_option_name', 120);
            $table->decimal('price_adjustment', 15, 2)->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['merchant_id', 'sales_transaction_item_id'], 'sales_tx_item_modifiers_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_transaction_item_modifiers');

        Schema::table('sales_transaction_items', function (Blueprint $table) {
            $table->dropColumn('modifiers_total');
        });

        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->dropIndex('sales_transactions_order_type_idx');
            $table->dropColumn([
                'order_type',
                'queue_number',
                'service_charge_rate',
                'service_charge_amount',
            ]);
        });
    }
};
