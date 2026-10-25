<?php

namespace Modules\Product\Http\Requests;

use Modules\Core\Abstracts\BaseFormRequest;

class SyncBranchAssortmentsRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';
    public function rules(): array
    {
        return [
            'assortment_group_ids' => ['present', 'array'],
            'assortment_group_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
