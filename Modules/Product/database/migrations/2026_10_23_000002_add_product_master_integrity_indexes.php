<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX product_variants_one_default_per_product ON product_variants (product_id) WHERE is_default = true AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX product_variant_units_one_base_unit ON product_variant_units (product_variant_id) WHERE is_base = true AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX price_lists_one_default_per_merchant ON price_lists (merchant_id) WHERE is_default = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_variants_one_default_per_product');
        DB::statement('DROP INDEX IF EXISTS product_variant_units_one_base_unit');
        DB::statement('DROP INDEX IF EXISTS price_lists_one_default_per_merchant');
    }
};
