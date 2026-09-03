<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_product_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();

            $table->enum('override_type', ['include', 'exclude']);
            $table->string('reason')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['branch_id', 'product_variant_id'], 'branch_product_override_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_overrides');
    }
};
