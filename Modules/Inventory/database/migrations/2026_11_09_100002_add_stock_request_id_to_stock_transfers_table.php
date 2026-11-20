<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('stock_request_id')->nullable()->after('to_warehouse_id')->constrained('stock_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropForeign(['stock_request_id']);
            $table->dropColumn('stock_request_id');
        });
    }
};

