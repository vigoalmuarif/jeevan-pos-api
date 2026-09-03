<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
             $table->unsignedBigInteger('id')->autoIncrement();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('plan_price_id')->nullable(); // snapshot referensi
            $table->decimal('base_price_snapshot', 12, 2)->nullable();
            $table->string('currency_snapshot', 3)->nullable();
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');
            $table->enum('status', ['active', 'past_due', 'cancelled', 'trial'])->default('trial');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('plan_id')->references('id')->on('plans');
            $table->foreign('plan_price_id')->references('id')->on('plan_prices')->onDelete('set null');
            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};