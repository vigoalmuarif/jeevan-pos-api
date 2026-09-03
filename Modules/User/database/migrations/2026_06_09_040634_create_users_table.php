<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')
                ->nullable()
                ->constrained('merchants')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->unique()->nullable();   // global unique
            $table->string('username')->unique(); // global unique
            $table->string('phone')->unique()->nullable();
            $table->string('avatar')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_all_branches')
                ->default(false)
                ->comment('Jika true, bisa akses semua branch (owner/manager)');
            $table->foreignId('current_active_branch')
            ->nullable()
            ->constrained('branches')
            ->cascadeOnDelete();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('merchant_id');
            $table->index('is_active');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};