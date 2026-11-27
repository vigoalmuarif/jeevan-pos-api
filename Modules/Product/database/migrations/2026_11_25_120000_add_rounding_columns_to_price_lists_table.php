<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_lists') && ! Schema::hasColumn('price_lists', 'rounding_unit')) {
            Schema::table('price_lists', function (Blueprint $table) {
                $table->unsignedInteger('rounding_unit')->nullable()->after('default_markup_percent');
                $table->string('rounding_mode', 10)->nullable()->after('rounding_unit');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('price_lists') && Schema::hasColumn('price_lists', 'rounding_unit')) {
            Schema::table('price_lists', function (Blueprint $table) {
                $table->dropColumn(['rounding_unit', 'rounding_mode']);
            });
        }
    }
};
