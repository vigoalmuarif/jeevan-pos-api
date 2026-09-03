<?php
// 2026_06_13_000018_create_merchant_addons_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_addons', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('addon_id');

            // per_unit: jumlah unit. bundle: jumlah paket. module: selalu 1.
            $table->integer('quantity');

            $table->boolean('is_active')->default(true);

            $table->decimal('price_snapshot', 12, 2);
            $table->string('currency_snapshot', 3)->default('IDR');
            $table->enum('billing_cycle_snapshot', ['monthly', 'yearly']);
            $table->unsignedBigInteger('addon_price_id')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('addon_id')->references('id')->on('addons')->onDelete('cascade');
            $table->foreign('addon_price_id')->references('id')->on('addon_prices')->onDelete('set null');
            $table->index(['merchant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_addons');
    }
};