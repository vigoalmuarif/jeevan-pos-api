<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePosPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'pin' => ['nullable', 'string', 'digits:6', 'confirmed'],
            'current_pin' => ['nullable', 'string', 'digits:6'],
            'current_password' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.digits' => 'PIN harus terdiri dari tepat 6 digit angka.',
            'pin.confirmed' => 'Konfirmasi PIN tidak cocok.',
            'current_pin.digits' => 'PIN saat ini harus terdiri dari 6 digit angka.',
        ];
    }
}
