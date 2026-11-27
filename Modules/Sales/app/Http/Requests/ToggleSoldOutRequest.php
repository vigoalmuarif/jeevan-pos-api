<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ToggleSoldOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            'product_variant_id' => ['required', 'integer', 'min:1'],
            'is_sold_out' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:120'],
        ];
    }
}
