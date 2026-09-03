<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement();
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('from_plan_id');
            $table->unsignedBigInteger('to_plan_id');
            $table->enum('change_type', ['upgrade', 'downgrade']);
            $table->enum('status', ['pending', 'applied', 'cancelled'])->default('pending');
            $table->timestamp('effective_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->onDelete('cascade');
            $table->foreign('from_plan_id')->references('id')->on('plans');
            $table->foreign('to_plan_id')->references('id')->on('plans');
            $table->index(['status', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
    }
};