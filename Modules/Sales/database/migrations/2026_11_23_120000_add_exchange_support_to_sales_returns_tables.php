<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('return_type', 20)->default('refund')->after('status');
            $table->decimal('exchange_total', 15, 2)->default(0)->after('total_refund');
            $table->decimal('settlement_amount', 15, 2)->default(0)->after('exchange_total');
            $table->decimal('total_cogs_exchange', 15, 2)->default(0)->after('total_cogs_reversed');

            $table->index(['merchant_id', 'return_type']);
        });

        // Backfill data retur eksisting (semuanya bertipe refund: settlement_amount = -total_refund)
        DB::table('sales_returns')
            ->where('return_type', 'refund')
            ->update([
                'settlement_amount' => DB::raw('-1 * total_refund'),
            ]);

        Schema::create('sales_return_exchange_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
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
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'sales_return_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_exchange_items');

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'return_type']);
            $table->dropColumn(['return_type', 'exchange_total', 'settlement_amount', 'total_cogs_exchange']);
        });
    }
};
