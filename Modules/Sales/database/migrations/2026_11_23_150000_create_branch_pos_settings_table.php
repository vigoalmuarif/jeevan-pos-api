<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_pos_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('default_ui_mode', 20)->nullable(); // grid, list
            $table->string('print_template', 30)->nullable(); // thermal_80mm, thermal_58mm, invoice_a5
            $table->decimal('service_charge_rate', 5, 2)->nullable();
            $table->boolean('allow_decimal_qty')->nullable();
            $table->boolean('enable_item_notes')->nullable();
            $table->boolean('barcode_fast_scan')->nullable();
            $table->boolean('require_customer')->nullable();
            $table->json('extra_config')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['merchant_id', 'branch_id'], 'branch_pos_settings_merchant_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_pos_settings');
    }
};
