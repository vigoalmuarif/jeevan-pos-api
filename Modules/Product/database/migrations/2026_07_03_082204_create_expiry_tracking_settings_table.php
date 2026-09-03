<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sengaja TIDAK menambah kolom is_track_expiry ke product_variants (Core).
     * Tabel ini milik ExtExpiryTracking sendiri, ber-FK ke product_variants,
     * sehingga arah dependency tetap benar: Ext -> Core, bukan sebaliknya.
     * Core Product module tidak perlu tahu konsep "expiry" sama sekali.
     */
    public function up(): void
    {
        Schema::create('expiry_tracking_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expiry_tracking_settings');
    }
};