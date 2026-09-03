<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu tabel menangani 3 kebutuhan sekaligus, diselesaikan oleh
     * PriceResolverService dengan urutan spesifisitas (paling spesifik menang):
     *
     *   branch_id + price_list_id + min_qty tertinggi <= qty diminta
     *   branch_id spesifik + price_list_id null (override branch, default tier)
     *   branch_id null     + price_list_id spesifik (tier berlaku semua cabang)
     *   branch_id null     + price_list_id null      -> fallback ke
     *       product_variant_units.default_price
     *
     * pricing_mode:
     *   fixed         -> price dipakai apa adanya
     *   margin        -> price dihitung dari cost variant_unit + markup%
     *   follow_center -> ikut default_price pusat (branch tidak override)
     *
     * reason wajib diisi di service layer (bukan constraint DB) ketika
     * fixed price berada di bawah floor price hasil markup minimum,
     * sebagai audit trail sesuai floor price protection.
     */
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_unit_id')->constrained('product_variant_units')->cascadeOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->decimal('min_qty', 15, 4)->default(1);
            $table->decimal('price', 15, 2);
            $table->enum('pricing_mode', ['fixed', 'margin', 'follow_center'])->default('fixed');
            $table->string('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['product_variant_unit_id', 'branch_id', 'price_list_id', 'min_qty'],
                'product_prices_resolver_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};