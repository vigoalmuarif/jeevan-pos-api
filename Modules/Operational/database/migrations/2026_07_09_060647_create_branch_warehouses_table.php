<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('branch_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['branch_id', 'warehouse_id']);
        });

        // Partial unique index: satu branch hanya boleh punya satu warehouse primary
        DB::statement('
            CREATE UNIQUE INDEX branch_warehouses_one_primary_per_branch
            ON branch_warehouses (branch_id)
            WHERE is_primary = true
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS branch_warehouses_one_primary_per_branch');
        Schema::dropIfExists('branch_warehouses');
    }
};
