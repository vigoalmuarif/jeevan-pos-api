<?php

namespace Modules\Product\Http\Requests;

use Modules\Core\Abstracts\BaseFormRequest;

class SyncAssortmentVariantsRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';
    public function rules(): array
    {
        return ['variant_ids' => ['present', 'array'], 'variant_ids.*' => ['required', 'integer', 'distinct']];
    }
}
