<?php

namespace Modules\Purchase\Http\Requests;

use Modules\Core\Http\Requests\DataTableRequest;

class GoodsReceiptIndexRequest extends DataTableRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'filter.status' => ['sometimes', 'in:draft,posted,voided'],
            'filter.supplier_id' => ['sometimes', 'integer', 'min:1'],
            'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'],
        ]);
    }
}
