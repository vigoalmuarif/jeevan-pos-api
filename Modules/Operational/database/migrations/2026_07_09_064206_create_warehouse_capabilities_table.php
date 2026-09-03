<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capability_type_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['warehouse_id', 'capability_type_id'], 'warehouse_capability_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_capabilities');
    }
};
