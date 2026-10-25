<?php

namespace Modules\Inventory\Http\Requests;

use Modules\Core\Http\Requests\DataTableRequest;

class WarehouseStockIndexRequest extends DataTableRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'filter.branch_id' => ['sometimes', 'integer', 'min:1'],
            'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'],
        ]);
    }
}
