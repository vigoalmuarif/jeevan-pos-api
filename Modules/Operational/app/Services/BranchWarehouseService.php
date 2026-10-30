<?php

namespace Modules\Operational\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Helpers\MerchantContext;
use Modules\Operational\Models\Branch;

class BranchWarehouseService
{
    /**
     * Replace seluruh relasi gudang cabang dan sinkronkan gudang utama.
     * Pemutakhiran primary_warehouse_id dilakukan di sini sebagai satu pintu.
     *
     * @param  list<int>  $warehouseIds
     */
    public function replaceAll(Branch $branch, array $warehouseIds, int $primaryWarehouseId): void
    {
        $merchantId = (int) MerchantContext::id();
        $warehouseIds = array_values(array_unique(array_map('intval', $warehouseIds)));

        $pivot = collect($warehouseIds)
            ->mapWithKeys(fn (int $warehouseId) => [
                $warehouseId => [
                    'merchant_id' => $merchantId,
                    'is_primary' => $warehouseId === $primaryWarehouseId,
                    'is_active' => true,
                ],
            ])
            ->all();

        $branch->warehouses()->sync($pivot);

        DB::table('branches')
            ->where('id', $branch->id)
            ->where('merchant_id', $merchantId)
            ->update([
                'primary_warehouse_id' => $primaryWarehouseId,
                'updated_at' => now(),
            ]);
    }
}
