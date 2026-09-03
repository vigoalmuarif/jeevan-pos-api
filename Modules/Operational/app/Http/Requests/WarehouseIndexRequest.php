<?php

namespace Modules\Operational\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseIndexRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
         return array_merge(parent::rules(), [
            'filter.is_active' => ['sometimes', 'integer'],
            'filter.is_main' => ['sometimes', 'integer'],
            'filter.branch_id' => ['sometimes', 'integer'],
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
