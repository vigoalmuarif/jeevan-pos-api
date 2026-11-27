<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'branch_id', 'code'], 'kitchen_stations_branch_code_unique');
            $table->index(['merchant_id', 'branch_id', 'is_active'], 'kitchen_stations_branch_active_idx');
        });

        Schema::create('kitchen_station_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('kitchen_station_id')->constrained('kitchen_stations')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['kitchen_station_id', 'category_id'], 'kitchen_station_categories_unique');
            $table->index(['merchant_id', 'category_id'], 'kitchen_station_categories_cat_idx');
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('sales_transaction_id')->constrained('sales_transactions')->cascadeOnDelete();
            $table->foreignId('kitchen_station_id')->nullable()->constrained('kitchen_stations')->nullOnDelete();
            $table->string('ticket_number', 40);
            $table->string('order_type', 20)->default('dine_in');
            $table->string('queue_number', 20)->nullable();
            $table->string('table_code', 30)->nullable();
            $table->string('table_name', 80)->nullable();
            $table->string('customer_name', 120)->nullable();
            $table->string('status', 20)->default('pending'); // pending, preparing, ready, served, voided
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'branch_id', 'status', 'sent_at'], 'kitchen_tickets_board_idx');
            $table->index(['merchant_id', 'sales_transaction_id'], 'kitchen_tickets_tx_idx');
        });

        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('kitchen_ticket_id')->constrained('kitchen_tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('sales_transaction_item_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('item_name', 255);
            $table->string('variant_name', 255)->nullable();
            $table->string('unit_name', 100)->nullable();
            $table->decimal('quantity', 15, 4);
            $table->json('modifiers_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['merchant_id', 'kitchen_ticket_id'], 'kitchen_ticket_items_ticket_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_items');
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('kitchen_station_categories');
        Schema::dropIfExists('kitchen_stations');
    }
};
