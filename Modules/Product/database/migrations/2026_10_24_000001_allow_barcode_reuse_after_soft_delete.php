<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BARCODE_INDEX = 'product_variant_units_merchant_id_barcode_unique';
    private const ACTIVE_BARCODE_INDEX = 'product_variant_units_active_barcode_unique';

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('product_variant_units', function ($table) {
                $table->dropUnique(self::BARCODE_INDEX);
            });
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE product_variant_units DROP CONSTRAINT IF EXISTS '.self::BARCODE_INDEX
            );
        } else {
            DB::statement('DROP INDEX IF EXISTS '.self::BARCODE_INDEX);
        }

        $now = now();
        $archivedProductIds = DB::table('products')
            ->whereNotNull('deleted_at')
            ->select('id');
        $archivedVariantIds = DB::table('product_variants')
            ->whereIn('product_id', $archivedProductIds)
            ->select('id');

        DB::table('product_variant_units')
            ->whereIn('product_variant_id', $archivedVariantIds)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        DB::table('product_variants')
            ->whereIn('product_id', $archivedProductIds)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]);

        DB::statement(
            'CREATE UNIQUE INDEX '.self::ACTIVE_BARCODE_INDEX.
            ' ON product_variant_units (merchant_id, barcode)'.
            ' WHERE barcode IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::ACTIVE_BARCODE_INDEX);

        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'pgsql') {
            Schema::table('product_variant_units', function ($table) {
                $table->unique(['merchant_id', 'barcode'], self::BARCODE_INDEX);
            });
        } else {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::BARCODE_INDEX.
                ' ON product_variant_units (merchant_id, barcode)'
            );
        }
    }
};
