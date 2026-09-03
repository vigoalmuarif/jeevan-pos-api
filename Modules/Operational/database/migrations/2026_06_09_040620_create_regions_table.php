<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_region_id')->nullable()
                ->constrained('regions')->nullOnDelete();

            $table->string('name');
            $table->string('code');
            $table->enum('type', ['province', 'city', 'custom'])->default('custom');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['merchant_id', 'code']);
            $table->index(['merchant_id', 'parent_region_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
