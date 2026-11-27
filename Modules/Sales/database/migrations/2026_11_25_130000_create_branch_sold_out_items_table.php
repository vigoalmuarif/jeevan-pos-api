<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_sold_out_items')) {
            Schema::create('branch_sold_out_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->date('sold_out_date')->nullable();
                $table->string('reason', 120)->nullable();
                $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['merchant_id', 'branch_id', 'product_variant_id'], 'branch_sold_out_unique');
                $table->index(['merchant_id', 'branch_id', 'sold_out_date'], 'branch_sold_out_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_sold_out_items');
    }
};
