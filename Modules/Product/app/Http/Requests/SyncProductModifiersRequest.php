<?php

declare(strict_types=1);

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncProductModifiersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'modifier_group_ids' => ['present', 'array'],
            'modifier_group_ids.*' => ['integer', 'min:1'],
        ];
    }
}
