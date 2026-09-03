<?php

namespace Modules\Product\Http\Requests;

use Modules\Core\Http\Requests\DataTableRequest;

class UnitIndexRequest extends DataTableRequest
{
   public function rules(): array
    {
        return array_merge(parent::rules(), [
            'filter.is_system' => ['sometimes', 'integer'],
            'filter.is_active' => ['sometimes', 'boolean'],
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
