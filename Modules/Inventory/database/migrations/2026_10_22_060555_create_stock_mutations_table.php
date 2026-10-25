<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger stok (kartu stok). APPEND-ONLY: tidak ada updated_at, tidak ada soft delete.
 * Koreksi dilakukan dengan mutasi pembalik (reversal), bukan edit/hapus.
 *
 * - mutation_type : string (bukan DB enum) agar Ext module bisa menambah tipe;
 *                   divalidasi oleh backed enum / registry di PHP.
 * - quantity      : bertanda (+ masuk, - keluar), satuan dasar.
 * - reference_*   : referensi polimorfik ke dokumen sumber (morph alias).
 * - reference_number : snapshot nomor dokumen agar kartu stok tidak perlu join.
 *
 * Pada PostgreSQL, trigger menolak UPDATE/DELETE sebagai pengaman integritas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();

            $table->string('mutation_type', 40);

            $table->decimal('quantity', 18, 4);                       // bertanda, satuan dasar
            $table->decimal('quantity_before', 18, 4);
            $table->decimal('quantity_after', 18, 4);

            $table->decimal('unit_cost', 18, 4)->default(0);          // HPP per satuan dasar saat mutasi
            $table->decimal('total_cost', 18, 4)->default(0);         // bertanda, = quantity * unit_cost
            $table->decimal('avg_cost_after', 18, 4)->default(0);     // snapshot moving average sesudah mutasi

            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number', 50)->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('mutated_at')->useCurrent();            // tanggal efektif mutasi
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Kartu stok per produk & gudang berdasarkan waktu
            $table->index(
                ['merchant_id', 'warehouse_id', 'product_variant_id', 'mutated_at', 'id'],
                'stock_mutations_card_index'
            );
            // Telusur dari dokumen sumber ke mutasinya
            $table->index(['reference_type', 'reference_id'], 'stock_mutations_reference_index');
            // Laporan per tipe mutasi
            $table->index(['merchant_id', 'mutation_type', 'mutated_at'], 'stock_mutations_type_index');
        });

        DB::statement('ALTER TABLE stock_mutations ADD CONSTRAINT stock_mutations_quantity_nonzero_check CHECK (quantity <> 0)');

//         if (DB::getDriverName() === 'pgsql') {
//             DB::unprepared(<<<'SQL'
// CREATE OR REPLACE FUNCTION prevent_stock_mutation_change() RETURNS trigger AS $$
// BEGIN
//     RAISE EXCEPTION 'stock_mutations bersifat append-only (% tidak diizinkan)', TG_OP;
// END;
// $$ LANGUAGE plpgsql;
// SQL);

//             DB::unprepared(<<<'SQL'
// CREATE TRIGGER stock_mutations_append_only
// BEFORE UPDATE OR DELETE ON stock_mutations
// FOR EACH ROW EXECUTE FUNCTION prevent_stock_mutation_change();
// SQL);
//         }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_mutations_append_only ON stock_mutations');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_stock_mutation_change()');
        }

        Schema::dropIfExists('stock_mutations');
    }
};
