<?php
// 2026_06_13_000017_create_merchant_industry_packages_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_industry_packages', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('industry_package_id');
            $table->boolean('is_active')->default(true);

            // Snapshot harga saat subscribe
            $table->decimal('price_snapshot', 12, 2);
            $table->string('currency_snapshot', 3)->default('IDR');
            $table->enum('billing_cycle_snapshot', ['monthly', 'yearly']);
            $table->unsignedBigInteger('industry_package_price_id')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('industry_package_id')->references('id')->on('industry_packages')->onDelete('cascade');
            $table->foreign('industry_package_price_id')->references('id')->on('industry_package_prices')->onDelete('set null');
            $table->unique(['merchant_id', 'industry_package_id']);
            $table->index(['merchant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_industry_packages');
    }
};