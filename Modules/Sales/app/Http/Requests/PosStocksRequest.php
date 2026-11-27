<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PosStocksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1'],
            'variant_ids' => ['required', 'array', 'min:1', 'max:200'],
            'variant_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
