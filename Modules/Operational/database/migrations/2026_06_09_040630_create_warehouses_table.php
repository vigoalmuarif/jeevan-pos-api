<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')
                  ->constrained('merchants')
                  ->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('code')
            ->comment('Kode gudang, e.g: WH-MAIN');
            $table->enum('type', ['storage', 'distribution_center'])
                ->default('storage');
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')
                  ->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            

            $table->unique(['merchant_id', 'code']);
            $table->unique(['merchant_id', 'name']);
            $table->index('slug');
            $table->index('code');
            $table->index('merchant_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};