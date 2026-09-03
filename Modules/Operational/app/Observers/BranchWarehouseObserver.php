<?php

namespace Modules\Operational\Observers;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\BranchWarehouse;

class BranchWarehouseObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Handle the BranchWarehouse "created" event.
     */
    public function created(BranchWarehouse $branchwarehouse): void 
    {
        if ($branchwarehouse->is_primary) {
            Branch::where('id', $branchwarehouse->branch_id)
                ->update(['primary_warehouse_id' => $branchwarehouse->warehouse_id]);
        }
    }

    /**
     * Handle the BranchWarehouse "updated" event.
     */
    public function updated(BranchWarehouse $branchwarehouse): void {}

    /**
     * Handle the BranchWarehouse "deleted" event.
     */
    public function deleted(BranchWarehouse $branchwarehouse): void 
    {
        if ($branchwarehouse->is_primary) {
            Branch::where('id', $branchwarehouse->branch_id)
                ->update(['primary_warehouse_id' => null]);
        }
    }

    /**
     * Handle the BranchWarehouse "restored" event.
     */
    public function restored(BranchWarehouse $branchwarehouse): void {}

    /**
     * Handle the BranchWarehouse "force deleted" event.
     */
    public function forceDeleted(BranchWarehouse $branchwarehouse): void {}
}
