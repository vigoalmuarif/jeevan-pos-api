<?php
// 2026_06_13_000016_create_addon_prices_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement();
            $table->unsignedBigInteger('addon_id');
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('IDR');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();

            $table->foreign('addon_id')->references('id')->on('addons')->onDelete('cascade');
            $table->index(['addon_id', 'billing_cycle', 'valid_from', 'valid_to'], 'idx_addon_prices_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_prices');
    }
};