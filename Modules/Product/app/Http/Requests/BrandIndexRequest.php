<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\DataTableRequest;

class BrandIndexRequest extends DataTableRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'filter.is_active' => ['sometimes', 'integer'],
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
