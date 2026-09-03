<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')
                ->constrained('merchants')
                ->cascadeOnDelete();
            $table->foreignId('primary_warehouse_id')->nullable()
            ->constrained('warehouses')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('code')
                ->comment('Prefix kode transaksi, e.g: JKT');
            $table->unsignedBigInteger('region_id')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('village_code')->nullable();
            $table->string('district_code')->nullable();
            $table->string('regency_code')->nullable();
            $table->string('province_code')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('logo')->nullable();
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')
                ->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('region_id')
                ->references('id')
                ->on('regions')
                ->nullOnDelete();


            $table->foreign('province_code')
                ->references('kode')
                ->on('wilayah')
                ->nullOnDelete();

            $table->foreign('regency_code')
                ->references('kode')
                ->on('wilayah')
                ->nullOnDelete();

            $table->foreign('district_code')
                ->references('kode')
                ->on('wilayah')
                ->nullOnDelete();

            $table->foreign('village_code')
                ->references('kode')
                ->on('wilayah')
                ->nullOnDelete();

            $table->unique(['merchant_id', 'name']);
            $table->unique(['merchant_id', 'code']);
            $table->index('merchant_id');
            $table->index('code');
            $table->index('slug');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
