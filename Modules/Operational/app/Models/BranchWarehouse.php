<?php

namespace Modules\Operational\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Core\Abstracts\BaseModel;

// use Modules\Operational\Database\Factories\BranchWarehouseFactory;


#[Table('branch_warehouses')]
class BranchWarehouse extends BaseModel
{
    use HasFactory;

}
