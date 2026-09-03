<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name');
            $table->string('industry_package_code')
                  ->nullable();
            $table->string('slug')->nullable()->unique()->comment('Used for subdomain');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('village_code')->nullable();
            $table->string('district_code')->nullable();
            $table->string('regency_code')->nullable();
            $table->string('province_code')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('logo')->nullable();
            $table->string('timezone')->default('Asia/Jakarta');
            $table->string('currency')->default('IDR');
            $table->string('locale')->default('id');
            $table->enum('status', [
                'active',
                'inactive',
                'suspended',
                'trial',
            ])->default('trial');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('slug');
            $table->index('status');
            $table->index('ulid');

            $table->foreign('industry_package_code')
                ->references('code')
                ->on('industry_packages')
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};