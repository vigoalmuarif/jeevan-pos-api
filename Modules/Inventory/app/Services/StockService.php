<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;

/** The single write path for current stock and the immutable stock ledger. */
class StockService
{
    public function recordMutation(
        int $warehouseId,
        int $productVariantId,
        float $baseQuantity,
        string $mutationType,
        ?float $incomingUnitCost = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $referenceNumber = null,
        ?string $notes = null,
        mixed $mutatedAt = null,
        // [DITAMBAH] Biaya masuk ASLI dari mutasi masuk yang sedang dibalik (mis. void penerimaan
        // barang). Hanya dipakai untuk mutasi keluar (quantity < 0). Lihat penjelasan di bawah.
        ?float $reversedUnitCost = null,
    ): object {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $quantity = round($baseQuantity, 4);
        if ($quantity == 0.0) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah mutasi stok tidak boleh nol.']);
        }

        DB::table('warehouse_stocks')->insertOrIgnore([
            'merchant_id' => $merchantId,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $productVariantId,
            'quantity' => 0,
            'avg_cost' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stock = DB::table('warehouse_stocks')
            ->where('merchant_id', $merchantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_variant_id', $productVariantId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            throw new \RuntimeException('Saldo stok tidak dapat dikunci untuk diperbarui.');
        }

        $before = (float) $stock->quantity;
        $averageCostBefore = (float) $stock->avg_cost;
        $after = round($before + $quantity, 4);

        if ($after < 0 && ! $this->allowsNegativeStock()) {
            throw ValidationException::withMessages([
                'items' => 'Stok tidak mencukupi. Pengurangan akan membuat saldo stok menjadi negatif.',
            ]);
        }

        if ($quantity > 0) {
            $unitCost = $incomingUnitCost ?? $averageCostBefore;
            $averageCostAfter = $before > 0
                ? (($before * $averageCostBefore) + ($quantity * $unitCost)) / $after
                : $unitCost;
        } else {
            // [DIUBAH] Mutasi keluar biasa: biaya = rata-rata berjalan, rata-rata tidak berubah.
            // Pembalikan mutasi masuk ($reversedUnitCost terisi): nilai persediaan yang keluar adalah
            // sebesar biaya masuk aslinya, sehingga moving average kembali ke posisi sebelum mutasi
            // masuk tersebut. Contoh: stok 10 @100, masuk 10 @200 -> 20 @150; void masuk ->
            // (20x150 - 10x200) / 10 = 100. Tanpa ini rata-rata tertinggal 150, padahal penerimaannya
            // sudah dibatalkan. (Tepat bila tidak ada mutasi lain di antaranya; selain itu pendekatan
            // terbaik tanpa menghitung ulang seluruh riwayat.)
            $unitCost = $reversedUnitCost ?? $averageCostBefore;
            $averageCostAfter = ($reversedUnitCost !== null && $after > 0)
                ? (($before * $averageCostBefore) + ($quantity * $reversedUnitCost)) / $after
                : $averageCostBefore;
        }

        $unitCost = round(max(0, $unitCost), 4);
        $averageCostAfter = $after == 0.0 ? 0 : round(max(0, $averageCostAfter), 4);
        $effectiveAt = $mutatedAt ? \Illuminate\Support\Carbon::parse($mutatedAt) : now();

        DB::table('warehouse_stocks')
            ->where('id', $stock->id)
            ->update([
                'quantity' => $after,
                'avg_cost' => $averageCostAfter,
                'last_mutated_at' => $effectiveAt,
                'updated_at' => now(),
            ]);

        $mutationId = DB::table('stock_mutations')->insertGetId([
            'merchant_id' => $merchantId,
            'warehouse_id' => $warehouseId,
            'product_variant_id' => $productVariantId,
            'mutation_type' => $mutationType,
            'quantity' => $quantity,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost' => $unitCost,
            'total_cost' => round($quantity * $unitCost, 4),
            'avg_cost_after' => $averageCostAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'mutated_at' => $effectiveAt,
            'created_by' => auth()->guard('merchant')->id(),
            'created_at' => now(),
        ]);

        return (object) [
            'id' => $mutationId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'quantity_before' => $before,
            'quantity_after' => $after,
        ];
    }

    private function allowsNegativeStock(): bool
    {
        $metadata = MerchantContext::get()?->metadata ?? [];

        return (bool) data_get($metadata, 'inventory.allow_negative_stock', false);
    }
}