<?php

declare(strict_types=1);

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreModifierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'selection_type' => ['required', 'string', Rule::in(['single', 'multiple'])],
            'is_required' => ['nullable', 'boolean'],
            'min_select' => ['nullable', 'integer', 'min:0', 'max:50'],
            'max_select' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'options' => ['required', 'array', 'min:1'],
            'options.*.name' => ['required', 'string', 'max:120'],
            'options.*.price_adjustment' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'options.*.is_default' => ['nullable', 'boolean'],
            'options.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'options.*.is_active' => ['nullable', 'boolean'],
        ];
    }
}
