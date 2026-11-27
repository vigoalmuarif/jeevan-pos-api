<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->foreignId('price_list_id')
                ->nullable()
                ->after('order_type')
                ->constrained('price_lists')
                ->nullOnDelete();
            $table->string('sales_channel', 60)->nullable()->after('price_list_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
            $table->dropColumn('sales_channel');
        });
    }
};
