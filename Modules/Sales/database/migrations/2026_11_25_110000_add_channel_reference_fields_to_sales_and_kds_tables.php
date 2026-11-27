<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_transactions') && ! Schema::hasColumn('sales_transactions', 'channel_order_ref')) {
            Schema::table('sales_transactions', function (Blueprint $table) {
                $table->string('channel_order_ref', 60)->nullable()->after('sales_channel');
            });
        }

        if (Schema::hasTable('kitchen_tickets') && ! Schema::hasColumn('kitchen_tickets', 'sales_channel')) {
            Schema::table('kitchen_tickets', function (Blueprint $table) {
                $table->string('sales_channel', 60)->nullable()->after('order_type');
                $table->string('channel_order_ref', 60)->nullable()->after('sales_channel');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kitchen_tickets') && Schema::hasColumn('kitchen_tickets', 'sales_channel')) {
            Schema::table('kitchen_tickets', function (Blueprint $table) {
                $table->dropColumn(['sales_channel', 'channel_order_ref']);
            });
        }

        if (Schema::hasTable('sales_transactions') && Schema::hasColumn('sales_transactions', 'channel_order_ref')) {
            Schema::table('sales_transactions', function (Blueprint $table) {
                $table->dropColumn('channel_order_ref');
            });
        }
    }
};
