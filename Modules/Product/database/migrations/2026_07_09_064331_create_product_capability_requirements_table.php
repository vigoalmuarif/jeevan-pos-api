<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_capability_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capability_type_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['product_id', 'capability_type_id'], 'product_capability_requirement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_capability_requirements');
    }
};
