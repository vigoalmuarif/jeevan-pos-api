<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_value', 15, 4)->default(1);
            $table->boolean('is_base')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
            $table->index(['product_id', 'is_base']);
        });

        $unitRows = DB::table('products as p')
            ->join('product_variants as pv', 'pv.product_id', '=', 'p.id')
            ->join('product_variant_units as pvu', 'pvu.product_variant_id', '=', 'pv.id')
            ->whereNull('p.deleted_at')
            ->whereNull('pv.deleted_at')
            ->whereNull('pvu.deleted_at')
            ->where('pvu.is_active', true)
            ->orderBy('p.id')
            ->orderByDesc('pv.is_default')
            ->orderByDesc('pv.is_active')
            ->orderBy('pv.id')
            ->orderByDesc('pvu.is_base')
            ->orderBy('pvu.id')
            ->get([
                'p.id as product_id',
                'p.merchant_id',
                'pv.id as variant_id',
                'pvu.unit_id',
                'pvu.conversion_value',
                'pvu.is_base',
            ]);

        $canonicalByProduct = [];
        $canonicalVariantByProduct = [];
        foreach ($unitRows as $row) {
            $canonicalVariantByProduct[$row->product_id] ??= $row->variant_id;
            if ($canonicalVariantByProduct[$row->product_id] !== $row->variant_id) {
                continue;
            }
            $canonicalByProduct[$row->product_id][$row->unit_id] = [
                'merchant_id' => $row->merchant_id,
                'product_id' => $row->product_id,
                'unit_id' => $row->unit_id,
                'conversion_value' => $row->conversion_value,
                'is_base' => $row->is_base,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach ($canonicalByProduct as $productId => $units) {
            DB::table('product_unit_conversions')->insert(array_values($units));

            $variantIds = DB::table('product_variants')
                ->where('product_id', $productId)
                ->whereNull('deleted_at')
                ->pluck('id');
            $now = now();

            DB::table('product_variant_units')
                ->whereIn('product_variant_id', $variantIds)
                ->whereNull('deleted_at')
                ->whereNotIn('unit_id', array_keys($units))
                ->update(['is_active' => false, 'deleted_at' => $now, 'updated_at' => $now]);

            foreach ($variantIds as $variantId) {
                foreach ($units as $unitId => $sharedUnit) {
                    $existing = DB::table('product_variant_units')
                        ->where('product_variant_id', $variantId)
                        ->where('unit_id', $unitId)
                        ->first();

                    if ($existing) {
                        $barcode = $existing->barcode;
                        if ($barcode !== null && $existing->deleted_at !== null) {
                            $barcodeIsInUse = DB::table('product_variant_units')
                                ->where('merchant_id', $sharedUnit['merchant_id'])
                                ->where('barcode', $barcode)
                                ->whereNull('deleted_at')
                                ->where('id', '<>', $existing->id)
                                ->exists();
                            if ($barcodeIsInUse) {
                                $barcode = null;
                            }
                        }

                        DB::table('product_variant_units')
                            ->where('id', $existing->id)
                            ->update([
                                'conversion_value' => $sharedUnit['conversion_value'],
                                'is_base' => $sharedUnit['is_base'],
                                'barcode' => $barcode,
                                'is_active' => true,
                                'deleted_at' => null,
                                'updated_at' => $now,
                            ]);
                    } else {
                        DB::table('product_variant_units')->insert([
                            'merchant_id' => $sharedUnit['merchant_id'],
                            'product_variant_id' => $variantId,
                            'unit_id' => $unitId,
                            'conversion_value' => $sharedUnit['conversion_value'],
                            'is_base' => $sharedUnit['is_base'],
                            'barcode' => null,
                            'cost' => 0,
                            'default_price' => 0,
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_unit_conversions');
    }
};
