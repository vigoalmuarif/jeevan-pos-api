<?php
// 2026_06_13_000015_create_addons_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->autoIncrement();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['per_unit', 'bundle', 'module']);

            // type = per_unit/bundle
            $table->string('target_limit_key')->nullable();
            $table->integer('bundle_quantity')->nullable(); // type = bundle only

            // type = module
            $table->unsignedBigInteger('module_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('modules')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};