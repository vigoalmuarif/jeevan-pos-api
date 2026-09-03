<?php
// 2026_06_13_000013_create_industry_package_prices_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industry_package_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement();
            $table->unsignedBigInteger('industry_package_id');
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('IDR');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();

            $table->foreign('industry_package_id')->references('id')->on('industry_packages')->onDelete('cascade');
            $table->index(['industry_package_id', 'billing_cycle', 'valid_from', 'valid_to'], 'idx_industry_pkg_prices_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_package_prices');
    }
};