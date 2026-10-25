<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();

            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('type', 20)->default('individual'); // individual | company (Enum di PHP)
            $table->string('contact_person', 150)->nullable();

            $table->string('phone', 25)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('tax_id', 30)->nullable(); // NPWP

            $table->text('address')->nullable();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->string('postal_code', 10)->nullable();

            $table->decimal('credit_limit', 18, 2)->default(0);          // 0 = tanpa kredit
            $table->unsignedSmallInteger('payment_term_days')->default(0); // 0 = tunai

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            // HasAudit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id', 'name']);
            $table->index(['merchant_id', 'phone']);
            $table->index(['merchant_id', 'customer_group_id']);
            $table->index(['merchant_id', 'is_active']);
        });

        // Kode unik per merchant; kode milik data soft-deleted boleh dipakai ulang.
        DB::statement(
            'CREATE UNIQUE INDEX customers_merchant_code_unique
             ON customers (merchant_id, code)
             WHERE deleted_at IS NULL'
        );

        DB::statement(
            'ALTER TABLE customers
             ADD CONSTRAINT customers_credit_limit_non_negative CHECK (credit_limit >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};