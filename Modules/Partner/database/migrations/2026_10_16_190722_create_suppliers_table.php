<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();

            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('contact_person', 150)->nullable();

            $table->string('phone', 25)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('tax_id', 30)->nullable(); // NPWP

            $table->text('address')->nullable();
            $table->string('region_code')->nullable();
            $table->string('postal_code', 10)->nullable();

            $table->unsignedSmallInteger('payment_term_days')->default(0); // 0 = tunai

            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_name', 150)->nullable();
            $table->string('bank_account_number', 50)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            // HasAudit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();


            $table->foreign('region_code')
                ->references('kode')
                ->on('wilayah')
                ->nullOnDelete();

            $table->index(['merchant_id', 'name']);
            $table->index(['merchant_id', 'phone']);
            $table->index(['merchant_id', 'is_active']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX suppliers_merchant_code_unique
             ON suppliers (merchant_id, code)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
