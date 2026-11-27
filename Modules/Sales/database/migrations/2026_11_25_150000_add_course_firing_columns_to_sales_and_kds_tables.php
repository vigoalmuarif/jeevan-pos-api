<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_transaction_items') && ! Schema::hasColumn('sales_transaction_items', 'course_number')) {
            Schema::table('sales_transaction_items', function (Blueprint $table) {
                $table->unsignedTinyInteger('course_number')->default(1)->after('notes');
                $table->boolean('is_held_for_kitchen')->default(false)->after('course_number');
            });
        }

        if (Schema::hasTable('kitchen_tickets') && ! Schema::hasColumn('kitchen_tickets', 'course_number')) {
            Schema::table('kitchen_tickets', function (Blueprint $table) {
                $table->unsignedTinyInteger('course_number')->default(1)->after('order_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kitchen_tickets') && Schema::hasColumn('kitchen_tickets', 'course_number')) {
            Schema::table('kitchen_tickets', function (Blueprint $table) {
                $table->dropColumn('course_number');
            });
        }

        if (Schema::hasTable('sales_transaction_items') && Schema::hasColumn('sales_transaction_items', 'course_number')) {
            Schema::table('sales_transaction_items', function (Blueprint $table) {
                $table->dropColumn(['course_number', 'is_held_for_kitchen']);
            });
        }
    }
};
