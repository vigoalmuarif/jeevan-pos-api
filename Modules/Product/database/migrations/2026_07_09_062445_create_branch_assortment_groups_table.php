<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_assortment_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assortment_group_id')->constrained()->cascadeOnDelete();

            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            $table->timestamps();

            $table->unique(['branch_id', 'assortment_group_id'], 'branch_assortment_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_assortment_groups');
    }
};
