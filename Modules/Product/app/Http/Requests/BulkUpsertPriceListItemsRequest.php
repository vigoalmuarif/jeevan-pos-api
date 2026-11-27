<?php

declare(strict_types=1);

namespace Modules\Product\Http\Requests;

use Modules\Core\Abstracts\BaseFormRequest;

class BulkUpsertPriceListItemsRequest extends BaseFormRequest
{
    protected string $permissionPrefix = 'product';

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_unit_id' => ['required', 'integer', 'min:1'],
            'items.*.override_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }
}
