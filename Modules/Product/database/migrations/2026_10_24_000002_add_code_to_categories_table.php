<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'code')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->after('name');
            });
        }

        $categories = DB::table('categories')
            ->whereNull('code')
            ->orWhere('code', '')
            ->select('id', 'slug')
            ->orderBy('id')
            ->get();

        foreach ($categories as $category) {
            $baseCode = substr(Str::upper(Str::slug($category->slug ?: 'CATEGORY', '-')), 0, 40);
            $baseCode = $baseCode !== '' ? $baseCode : 'CATEGORY';
            $code = $baseCode;
            $suffix = 1;

            while (DB::table('categories')
                ->where('code', $code)
                ->exists()) {
                $code = $baseCode.'-'.$suffix++;
            }

            DB::table('categories')->where('id', $category->id)->update(['code' => $code]);
        }

        if (! Schema::hasIndex('categories', ['merchant_id', 'code'], 'unique')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unique(['merchant_id', 'code']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('categories', ['merchant_id', 'code'], 'unique')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropUnique(['merchant_id', 'code']);
            });
        }
    }
};
