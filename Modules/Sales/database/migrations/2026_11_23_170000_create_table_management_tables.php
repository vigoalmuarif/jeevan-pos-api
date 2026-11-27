<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name', 100);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id', 'branch_id', 'is_active'], 'dining_areas_branch_active_idx');
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('dining_area_id')->nullable()->constrained('dining_areas')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name', 80);
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->string('shape', 20)->default('square'); // square, round, rectangle
            $table->string('status', 20)->default('available'); // available, occupied, reserved, cleaning
            $table->foreignId('active_transaction_id')->nullable()->constrained('sales_transactions')->nullOnDelete();
            $table->unsignedSmallInteger('guest_count')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'branch_id', 'code'], 'dining_tables_branch_code_unique');
            $table->index(['merchant_id', 'branch_id', 'status', 'is_active'], 'dining_tables_branch_status_idx');
        });

        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->foreignId('dining_table_id')->nullable()->after('order_type')->constrained('dining_tables')->nullOnDelete();
            $table->unsignedSmallInteger('guest_count')->nullable()->after('dining_table_id');

            $table->index(['merchant_id', 'dining_table_id'], 'sales_transactions_table_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_transactions', function (Blueprint $table) {
            $table->dropIndex('sales_transactions_table_idx');
            $table->dropConstrainedForeignId('dining_table_id');
            $table->dropColumn('guest_count');
        });

        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('dining_areas');
    }
};
