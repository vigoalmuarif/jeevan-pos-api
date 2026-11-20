<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('can_be_sold')->default(true)->after('track_stock');
            $table->index(['merchant_id', 'can_be_sold'], 'products_merchant_can_be_sold_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_merchant_can_be_sold_index');
            $table->dropColumn('can_be_sold');
        });
    }
};

