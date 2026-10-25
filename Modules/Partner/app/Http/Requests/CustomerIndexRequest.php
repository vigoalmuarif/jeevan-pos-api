<?php

namespace Modules\Partner\Http\Requests;

use Modules\Core\Http\Requests\DataTableRequest;

class CustomerIndexRequest extends DataTableRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'filter.is_active' => ['sometimes', 'boolean'],
            'filter.customer_group_id' => ['sometimes', 'integer', 'min:1'],
            'filter.region_id' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
