<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->decimal('default_markup_percent', 6, 2)->nullable()->after('code');
            $table->string('description', 255)->nullable()->after('default_markup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table) {
            $table->dropColumn(['default_markup_percent', 'description']);
        });
    }
};
