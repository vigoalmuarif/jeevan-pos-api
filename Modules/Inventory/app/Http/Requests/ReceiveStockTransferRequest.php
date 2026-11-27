<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'received' => ['nullable', 'array'],
            'received.*' => ['nullable', 'numeric', 'min:0'],
            'receive_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
